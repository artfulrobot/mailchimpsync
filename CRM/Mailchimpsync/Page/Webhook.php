<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_Page_Webhook extends CRM_Core_Page {

  public function run() {
    if (!CRM_Mailchimpsync::webhookKeyIsValid($_GET['secret'])) {
      CRM_Utils_System::civiExit(401);
    }

    // @todo sense check that this webhook does not have API as a cause.

    // Hand off to separate methods.
    $method = 'process' . ucfirst($_POST['type'] ?? 'undefined');
    if (method_exists($this, $method)) {
      $response_code = $this->$method($_POST);
    }

    CRM_Utils_System::civiExit($response_code);
  }

  /**
   * Handle subscribe requests
   */
  public function processSubscribe($data) {
    return $this->processProfile($data);
  }

  /**
   * Handle unsubscribe requests
   *
   * @param array $data like this:
   *
   * "type": "unsubscribe",
   * "fired_at": "2009-03-26 21:40:57",
   * "data[action]": "unsub",
   * "data[reason]": "manual",
   * "data[id]": "8a25ff1d98",
   * "data[list_id]": "a6b5da1054",
   * "data[email]": "api+unsub@mailchimp.com",
   * "data[email_type]": "html",
   * "data[merges][EMAIL]": "api+unsub@mailchimp.com",
   * "data[merges][FNAME]": "Mailchimp",
   * "data[merges][LNAME]": "API",
   * "data[merges][INTERESTS]": "Group1,Group2",
   * "data[ip_opt]": "10.20.10.30",
   * "data[campaign_id]": "cb398d21d2"
   *
   * @return int HTTP status response code (200 is good)
   */
  public function processUnsubscribe($data) {
    return $this->unsubscribeOrClean($data, 'unsubscribed');
  }

  /**
   * Handle profile update requests
   */
  public function processProfile($data) {
    $list_id = $this->extractListId($data);
    $audience = CRM_Mailchimpsync_Audience::newFromListId($list_id);
    $email = $this->requireParam($data, 'email');
    $audience->syncSingle($email);
    return 200;
  }

  /**
   * Handle email cleaned requests
   */
  public function processCleaned($data) {
    return $this->unsubscribeOrClean($data, 'cleaned');
  }

  /**
   * Handle email update requests
   */
  public function processUpemail($data) {
    $list_id = $this->extractListId($data);
    $audience = CRM_Mailchimpsync_Audience::newFromListId($list_id);
    $new_email = $this->requireParam($data, 'new_email');
    $old_email = $this->requireParam($data, 'old_email');

    $cache_item = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_item->mailchimp_email = $old_email;
    $cache_item->list_id = $list_id;
    if ($cache_item->find(1)) {
      // Found this person.
      $contact_id = $cache_item->civicrm_contact_id;
      if ($contact_id) {
        // We know the contact

        // See if we have the new email already.
        $results = civicrm_api3('Email', 'getcount',
          ['email' => $new_email, 'contact_id' => $contact_id ]);
        if ($results == 0) {
          // Nope, it's new to us. Create it as a bulk mail email.
          civicrm_api3('Email', 'create', [
            'email'       => $new_email,
            'contact_id'  => $contact_id,
            'is_bulkmail' => 1,
          ]);
        }

        // Update our cache entry to use the new email.
        $cache_item->mailchimp_email = $new_email;
        $cache_item->mailchimp_member_id = $audience->getMailchimpApi()->getMailchimpMemberIdFromEmail($new_email);
        $cache_item->save();
      }
    }
  }

  /**
   * Extract list_id and check it's one we sync.
   *
   * @param array $data
   * @return string
   * @throws InvalidArgumentException if no list ID in input.
   * @throws CRM_Mailchimpsync_CannotSyncException if not one of our sync-ed lists.
   */
  public function extractListId($data) {
    if (empty($data['list_id'])) {
      throw new InvalidArgumentException("Missing List ID");
    }
    $config = CRM_Mailchimpsync::getConfig();
    if (!isset($config['lists'][$data['list_id']])) {
      throw new CRM_Mailchimpsync_CannotSyncException("$data[list_id] is not a list we keep in sync.");
    }
    return $data['list_id'];
  }
  /**
   * Extract variable or throw exception.
   *
   * @param array $data
   * @param string $prop
   *
   * @return string
   * @throws InvalidArgumentException if no list ID in input.
   */
  public function requireParam($data, $prop) {
    if (empty($data[$prop])) {
      throw new InvalidArgumentException("Missing '$prop' in data");
    }
    return $data[$prop];
  }
  public function unsubscribeOrClean($data, $type) {
    $list_id = $this->extractListId($data);
    $audience = CRM_Mailchimpsync_Audience::newFromListId($list_id);
    $email = $this->requireParam($data, 'email');
    $cache_item = $audience->syncSingle($email);
    if ($cache_item && $type === 'cleaned') {
      // Put email on hold.
      $results = civicrm_api3('Email', 'get', [
        'email' => $email, 'contact_id' => $cache_item->civicrm_contact_id
      ]);
      foreach ($results['values'] ?? [] as $record) {
        civicrm_api3('Email', 'create', [
          'id'        => $record['id'],
          'on_hold'   => 1,
          'hold_date' => date('YmdHis'),
        ]);
      }
    }
    return 200;
  }
}
