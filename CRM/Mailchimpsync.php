<?php
/**
 * Main helper class.
 *
 * @licence AGPL-3
 * @copyright Rich Lott / Artful Robot
 */

class CRM_Mailchimpsync
{
  protected static $updateGroupsInCacheTableHasRun=FALSE;
  /**
   * Returns an API object for the given key.
   *
   * These are cached per API key.
   *
   * @param string Mailchimp API key
   * @return CRM_Mailchimpsync_MailchimpApiInterface
   */
  public static function getMailchimpApi(string $key, $reset=FALSE) {
    if ($reset || !isset(\Civi::$statics['mailchimpsync_apis'][$key])) {
      if (substr($key, 0,5) == 'mock_') {
        $api = new CRM_Mailchimpsync_MailchimpApiMock($key);
      }
      else {
        $api = new CRM_Mailchimpsync_MailchimpApiLive($key);
      }
      \Civi::$statics['mailchimpsync_apis'][$key] = $api;
    }
    return \Civi::$statics['mailchimpsync_apis'][$key];
  }
  /**
   * Access CiviCRM setting for main config.
   *
   * @return array.
   */
  public static function getConfig() {
    return json_decode(Civi::settings()->get('mailchimpsync_config'), TRUE);
  }
  /**
   * Set CiviCRM setting for main config.
   *
   * @param array $config
   */
  public static function setConfig($config) {
    Civi::settings()->set('mailchimpsync_config', json_encode($config));
  }
  /**
   * Submit batches for all lists.
   *
   * @return array with list_ids in the keys and the number of updates submitted as the values.
   */
  public static function submitBatches() {
    $config = CRM_Mailchimpsync::getConfig();
    $results = [];
    foreach ($config['lists'] as $list_id => $details) {
      $audience = CRM_Mailchimpsync_Audience::newFromListId($list_id);
      $c = $audience->submitBatch();
      if ($c > 0) {
        $results[$list_id] = $c;
      }
    }
    return $results;
  }
  /**
   * Fetch batches for each API key and update our batches table.
   *
   * Nb. this is only done when we have not processed all batches.
   */
  public static function fetchBatches() {
    $batches = [];
    $config = CRM_Mailchimpsync::getConfig();

    $list_ids = CRM_Core_DAO::executeQuery(
      "SELECT DISTINCT mailchimp_list_id i FROM civicrm_mailchimpsync_batch WHERE response_processed = 0"
    )->fetchMap('i', 'i');
    $api_keys = [];
    foreach ($list_ids as $list_id) {
      $api_keys[ $config['lists'][$list_id]['apiKey'] ] = 1;
    }

    foreach (array_keys($api_keys) as $api_key) {
      $api = static::getMailchimpApi($api_key);
      $result = $api->get('batches')['batches'] ?? [];
      foreach ($result as $batch) {
        $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
        $bao->mailchimp_batch_id = $batch['id'];
        if ($bao->find(1)) {
          $bao->status = $batch['status'];
          $bao->submitted_at = $batch['submitted_at'];
          $bao->completed_at = $batch['completed_at'];
          $bao->finished_operations = $batch['finished_operations'];
          $bao->errored_operations = $batch['errored_operations'];
          $bao->total_operations = $batch['total_operations'];
          $bao->save();
          $_ = [];
          $bao->storeValues($bao, $_);
          $batches[$bao->mailchimp_list_id][$bao->mailchimp_batch_id] = $_;

          // If the process has completed, process it now instead of waiting for the webhook.
          // The risk here is that it takes too long and we hit a timeout.
          // if ($bao->status === 'finished') {
          //   $bao->processCompletedBatch($batch);
          // }
        }
      }
    }
    return $batches;
  }
  /**
   * Get an array of Integer Group ID used for any 2 way sync.
   *
   * @return Array
   */
  public static function getAllGroupIds() {
    $group_ids = [];
    $config = static::getConfig();
    foreach ($config['lists'] as $list) {
      $group_ids[] = (int) $list['subscriptionGroup'];
      foreach ($list['interests'] ?? [] as $group_id) {
        $group_ids[] = (int) $group_id;
      }
    }
    return $group_ids;
  }
  /**
   * Update the 'civicrm_groups' field in our cache table.
   *
   * @param bool $reset
   */
  public static function updateGroupsInCacheTable($reset=FALSE) {
    if (static::$updateGroupsInCacheTableHasRun && !$reset) {
      // Only do this once per session.
      return;
    }
    static::$updateGroupsInCacheTableHasRun = TRUE;

    // Get array of groups we care about
    $group_ids = static::getAllGroupIds();
    if ($group_ids) {
      $group_ids_clause = "group_id IN (" . implode(',', $group_ids) . ')';
    }
    else {
      // In the case that there's no groups (e.g. just set up), just create an empty table.
      $group_ids_clause = '0';
    }

    // Increase the max length for group concat.
    // Nb. the following line is supposed to be the same, it's unclear to me when you would choose one or the other.
    // CRM_Core_DAO::executeQuery("SET @@SESSION.group_concat_max_len = 1000000;");
    CRM_Core_DAO::executeQuery("SET SESSION group_concat_max_len = 1000000;");

    $sql = "
        UPDATE civicrm_mailchimpsync_cache c
        LEFT JOIN (
          SELECT contact_id, GROUP_CONCAT(CONCAT_WS(';', group_id, status, date) SEPARATOR '|') subs
          FROM civicrm_subscription_history h1
          WHERE
            $group_ids_clause
            AND contact_id IS NOT NULL
            AND NOT EXISTS (
              SELECT id FROM civicrm_subscription_history h2
              WHERE h2.group_id = h1.group_id
              AND h2.contact_id = h1.contact_id
              AND h2.id > h1.id)
          GROUP BY contact_id
        ) AS subs_results ON c.civicrm_contact_id = subs_results.contact_id
        SET c.civicrm_groups = subs_results.subs
      ";
    CRM_Core_DAO::executeQuery($sql);
  }
}
