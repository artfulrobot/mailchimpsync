<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * MailchimpsyncCache.create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_mailchimpsync_cache_create_spec(&$spec) {
  // $spec['some_parameter']['api.required'] = 1;
}

/**
 * MailchimpsyncCache.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_cache_create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailchimpsyncCache.delete API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_cache_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailchimpsyncCache.get API
 */
function _civicrm_api3_mailchimpsync_cache_get_spec(&$spec) {
  $spec['troubleshoot'] = [
    'description' => 'Set this to 1 to include related data that is relatively expensive to compute.',
  ];
}
/**
 * MailchimpsyncCache.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_cache_get($params) {
  $returnValues = CRM_Mailchimpsync_BAO_MailchimpsyncCache::apiSearch($params);
  // $returnValues = _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params);

  // Array of list IDs to contactIds.
  $contact_ids = [];
  if (empty($params['options']['is_count']) && !empty($params['troubleshoot']) && !empty($returnValues['values'])) {
    $contact_ids = array_filter(array_column($returnValues['values'], 'civicrm_contact_id'));
    $group_ids = CRM_Mailchimpsync::getAllGroupIds();
    if ($contact_ids && $group_ids) {
      /*
        // 2019-11-01
      // At first I thought this should be live, but actually it makes more sense for us
      // to show whateever is in the cache.
        // 2019-12-12
      // I'm not clear any more on this thinking. Live seems better and it's how the getStats works, too.
      // ? could we call updateCiviCRMGroups for these records now? NO, must not change data in a get request.

      $group_ids = implode(',', $group_ids);
      $contact_ids = implode(',', $contact_ids);

      CRM_Core_DAO::executeQuery("SET SESSION group_concat_max_len = 1000000;");
      $sql = "SELECT contact_id, GROUP_CONCAT(CONCAT_WS(';', group_id, status, date) SEPARATOR '|') subs
        FROM civicrm_subscription_history h1
        WHERE
        group_id IN ($group_ids)
        AND contact_id IN ($contact_ids)
        AND NOT EXISTS (
          SELECT id FROM civicrm_subscription_history h2
          WHERE h2.group_id = h1.group_id
          AND h2.contact_id = h1.contact_id
          AND h2.id > h1.id)
          GROUP BY contact_id;";
      $d = CRM_Core_DAO::executeQuery($sql)->fetchMap('subs', 'contact_id');
      */

      $names = civicrm_api3('Contact', 'get', ['return' => 'display_name', 'id' => ['IN' => $contact_ids]]);

      foreach ($returnValues['values'] as &$row) {
        $audience = CRM_Mailchimpsync_Audience::newFromListId($row['mailchimp_list_id']);
        $parsed = $audience->parseSubs($row['mailchimp_updated'] ?? NULL, $row['civicrm_groups']);
        $row['civicrm_status'] = $parsed[$audience->getSubscriptionGroup()]['status'];
        $row['civicrm_updated'] = $parsed[$audience->getSubscriptionGroup()]['updated'];
        $row['most_recent'] = $parsed[$audience->getSubscriptionGroup()]['mostRecent'];
        $row['civicrm_other_groups'] = $parsed;
        $row['mailchimp_data'] = json_encode(unserialize($row['mailchimp_data']), JSON_PRETTY_PRINT);
        $row['civicrm_data'] = json_encode(unserialize($row['civicrm_data']), JSON_PRETTY_PRINT);

        $row['civicrm_display_name'] = $names['values'][$row['civicrm_contact_id']]['display_name'] ?? 'Unknown';

        // Look up the last 3 updates to see if there are any failures.
        $sql = "SELECT u.data, u.completed, u.error_response,
                       b.submitted_at, b.completed_at, b.status
          FROM civicrm_mailchimpsync_update u
          LEFT JOIN civicrm_mailchimpsync_batch b ON u.mailchimpsync_batch_id = b.id
          WHERE u.mailchimpsync_cache_id = %1
          ORDER BY u.id DESC
          LIMIT 3";
        $fails = CRM_Core_DAO::executeQuery($sql, [1=>[$row['id'], 'String']]);
        $row['errors'] = '';
        $row['updates'] = [];
        while ($fails->fetch()) {
          $update_row = $fails->toArray();
          $update_row['status'] = $fails->completed ? 'ok' : 'pending';
          $update_row['error'] = '';
          $update_row['submitted_at'] = $fails->submitted_at;
          $update_row['completed_at'] = $fails->completed_at;
          $update_row['batch_status'] = $fails->status;

          //$update_row['data'] = unserialize($update_row)
          unset($update_row['error_response']);

          $fail = json_decode($fails->error_response);
          if ($fail) {
            $update_row['error'] = "{$fail->title}: {$fail->detail}";
            if ($fail->title == 'Member Exists') {
              $update_row['error'] .= ' ' . E::ts( "One cause of 'Member Exists' errors is that you have two separate contacts in CiviCRM for the same email, which leads to an impossible sync situation (because feasibly you could subscribe one contact and unsubscribe the other!). You should check and merge contacts if you find duplicates." );
            }
            $update_row['status'] = 'error';
          }
          if (!empty($fail->errors)) {
            // field based errors.
            $update_row['error'] .= "\n" . json_encode($fail->errors);
          }
          $row['updates'][] = $update_row;
        }

        // General errors - reasons why the sync status is fail.
        if ($row['sync_status'] === 'fail') {
          if ($row['mailchimp_status'] === 'cleaned') {
            // We can explain this one.
            $row['errors'] = E::ts('"Cleaned" contacts cannot be resubscribed.');
          }
          else {
            // Set up default message.
            $row['errors'] = 'Mailchimp update error.';

            // Are there other rows held back by other emails owned by this contact?
            $other_emails = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
            $other_emails->mailchimp_list_id = $row['mailchimp_list_id'];
            $other_emails->civicrm_contact_id = $row['civicrm_contact_id'];
            $other_emails->find();
            $list = [];
            while ($other_emails->fetch()) {
              if ($other_emails->id == $row['id']) {
                continue;
              }
              if ($other_emails->isSubscribedAtMailchimp()) {
                $list[] = $other_emails->mailchimp_email;
              }
            }
            if ($list) {
              // Update error message.
              $c = count($list);
              $row['errors'] = "This CiviCRM contact has " . ($c + 1) . " emails. This one is unsubscribed at Mailchimp, "
                . "but the other " . (($c>1) ? 's' : '') . " - " . implode(' and ', $list) . " are subscribed. This shows as a "
                . "fail here but it's fine that we're ignoring it.";
            }
            else {
              // Maybe it's cos it's on hold.
              $email_dao = new CRM_Core_DAO_Email();
              $email_dao->contact_id = $row['civicrm_contact_id'];
              $email_dao->on_hold = 0;
              if (!$email_dao->count()) {
                $row['errors'] = E::ts("This contact's email is on hold in CiviCRM so we're not going to subscribe them.");
              }
            }
          }
        }
        unset($row);
      }

    }
  }
  return $returnValues;
}
