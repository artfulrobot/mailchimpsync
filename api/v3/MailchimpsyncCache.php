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
  $returnValues = _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params);

  // Array of list IDs to contactIds.
  $contact_ids = [];
  if (empty($params['options']['is_count']) && !empty($params['troubleshoot']) && !empty($returnValues['values'])) {
    $contact_ids = array_filter(array_column($returnValues['values'], 'civicrm_contact_id'));
    $group_ids = CRM_Mailchimpsync::getAllGroupIds();
    if ($contact_ids && $group_ids) {
      /*
      // At first I thought this should be live, but actually it makes more sense for us
      // to show whateever is in the cache.

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
        $parsed = $audience->parseSubs($row['mailchimp_updated'], $row['civicrm_groups']);
        $row['civicrm_status'] = $parsed[$audience->getSubscriptionGroup()]['status'];
        $row['civicrm_updated'] = $parsed[$audience->getSubscriptionGroup()]['updated'];
        $row['most_recent'] = $parsed[$audience->getSubscriptionGroup()]['mostRecent'];
        $row['civicrm_other_groups'] = $parsed;

        $row['civicrm_display_name'] = $names['values'][$row['civicrm_contact_id']]['display_name'] ?? 'Unknown';

        if ($row['sync_status'] === 'fail') {
          // As this has failed, look up up to 3 recent failures.
          $sql = "SELECT id, error_response
            FROM civicrm_mailchimpsync_update
            WHERE mailchimpsync_cache_id = %1 AND error_response IS NOT NULL AND error_response != ''
            ORDER BY id DESC
            LIMIT 3";
          $fails = CRM_Core_DAO::executeQuery($sql, [1=>[$row['id'], 'String']])->fetchMap('id', 'error_response');
          $row['errors'] = '';
          if ($fails) {
            foreach ($fails as $fail) {
              $fail = json_decode($fail);
              $row['errors'] .= "{$fail->title}: {$fail->detail}";
            }
          }
          else {
            if ($row['mailchimp_status'] === 'cleaned') {
              // We can explain this one.
              $row['errors'] = '"Cleaned" contacts cannot be resubscribed.';
            }
            else {
              $row['errors'] = 'Unknown error: ' . implode("\n", $fails) ;
            }
          }
        }
        unset($row);
      }

    }
  }
  return $returnValues;
}
