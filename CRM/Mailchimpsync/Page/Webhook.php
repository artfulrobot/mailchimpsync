<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_Page_Webhook extends CRM_Core_Page {

  public function run() {

    if (!CRM_Mailchimpsync::webhookKeyIsValid($_GET['secret'] ?? '')) {
      // We must have the secret.
      http_response_code(401);
      CRM_Utils_System::civiExit();
    }

    if (empty($_POST)) {
      // Mailchimp does this to test whether the URL works.
      CRM_Utils_System::civiExit();
    }

    Civi::log()->info("Webhook received:\n" . json_encode($_POST));
    // @todo sense check that this webhook does not have API as a cause.

    try {
      // Hand off to separate methods.
      $method = 'process' . ucfirst($_POST['type'] ?? 'undefined');
      if (method_exists($this, $method)) {
        $response_code = $this->$method($_POST);
      }
      elseif ($method === 'processCampaign') {
        Civi::log()->warning("Webhook set to include Campaign events - this is not a good idea.");
        $response_code = 204;
      }
      else {
        throw new InvalidArgumentException("Mailchimpsync webhook called with invalid type\n" . json_encode($_POST, JSON_PRETTY_PRINT));
      }
    }
    catch (CRM_Mailchimpsync_CannotSyncException $e) {
      Civi::log()->info($e->getMessage());
      $response_code = 204;
    }
    catch (Exception $e) {
      Civi::log()->error("Mailchimp sync webhook error: " . $e->getMessage(), ['POST' => $_POST, 'exception' => $e]);
      $response_code = 400;
    }

    http_response_code($response_code);
    CRM_Utils_System::civiExit();
  }

  /**
   * Handle subscribe requests
   */
  public function processSubscribe($data) {
    $list_id = $this->extractListId($data);
    $audience = CRM_Mailchimpsync_Audience::newFromListId($list_id);
    $email = $this->requireParam($data, 'email');
    $audience->syncSingle($email);

    return 200;
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
    $cache_item = $audience->syncSingle($email);

    // Profile updates mean a user has interacted with their profile.
    // Therefore we can consider that they are confirming their correct name.
    // So check we still have correct name.
    if ($cache_item && $cache_item->civicrm_contact_id>0) {
      $contact = civicrm_api3('Contact', 'getsingle', ['id' => $cache_item->civicrm_contact_id]);
      $contact_updates = [];
      foreach (['first_name' => 'FNAME', 'last_name' => 'LNAME'] as $c => $m) {
        if (!empty($data['data']['merges'][$m]) && $data['data']['merges'][$m] != $contact[$c]) {
          $contact_updates[$c] = $data['data']['merges'][$m];
        }
      }
      if ($contact_updates) {
        civicrm_api3('Contact', 'create', ['id' => $contact['id']] + $contact_updates);
      }
    }

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
    return 200;
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
    $list_id = $this->requireParam($data, 'list_id');
    $config = CRM_Mailchimpsync::getConfig();
    if (!isset($config['lists'][$list_id])) {
      throw new CRM_Mailchimpsync_CannotSyncException("$data[list_id] is not a list we keep in sync.");
    }
    return $list_id;
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
  public function requireParam($webhook_post_data, $prop) {
    if (empty($webhook_post_data['data'][$prop])) {
      throw new InvalidArgumentException("Missing '$prop' in data");
    }
    return $webhook_post_data['data'][$prop];
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
