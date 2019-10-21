<?php
/**
 * Main helper class.
 *
 * @licence AGPL-3
 * @copyright Rich Lott / Artful Robot
 */

class CRM_Mailchimpsync
{
  protected static $temp_group_contact_created=FALSE;
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
   */
  public static function setConfig($config) {
    Civi::settings()->set('mailchimpsync_config', $config);
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
   * Nb. this is only done if we have any batches outstanding.
   */
  public static function fetchBatches() {
    $batches = [];
    $config = CRM_Mailchimpsync::getConfig();

    $list_ids = CRM_Core_DAO::executeQuery(
      "SELECT DISTINCT mailchimp_list_id FROM civicrm_mailchimpsync_batch WHERE status != 'finished'"
    )->fetchCol();
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
          if ($bao->status === 'finished') {
            // DISABLED $bao->processCompletedBatch($batch);
          }
        }
      }
    }
    return $batches;
  }
  /**
   * Create a temporary table with each contact and the details of their latest
   * status in relation to the groups we care about.
   *
   * This table can then be used for subscription group and interest group syncs.
   *
   * @param mixed $relevant_since
   */
  public function ensureGroupMembershipTableExists($relevant_since) {
    if (!static::$temp_group_contact_created) {
      // Get array of groups we care about
      $group_ids = [];
      $config = $this->getConfig();
      foreach ($config['lists'] as $list) {
        $group_ids[] = (int) $list['subscriptionGroup'];
        foreach ($list['interests'] ?? [] as $group_id) {
          $group_ids[] = (int) $group_id;
        }
      }
      if ($group_ids) {
        $group_ids_clause = "group_id IN (" . implode(',', $group_ids) . ')';
      }
      else {
        // In the case that there's no groups (e.g. just set up), just create an empty table.
        $group_ids_clause = '0';
      }

      if ($relevant_since) {
        $and_since = "AND h1.date >= %2";
        $params = [1 => [date(DATE_ISO8601, $relevant_since), 'String']];
      }
      else {
        $and_since = '';
        $params = [];
      }

      $sql = "
        CREATE TEMPORARY TABLE temp_mailchimpsync_subscriptions
        SELECT contact_id, GROUP_CONCAT(CONCAT_WS(';', group_id, status, date) SEPARATOR '|') subs
        FROM civicrm_subscription_history h1
        WHERE
          $group_ids_clause
          AND contact_id IS NOT NULL
          $and_since
          AND NOT EXISTS (
            SELECT id FROM civicrm_subscription_history h2
            WHERE h2.group_id = h1.group_id
            AND h2.contact_id = h1.contact_id
            AND h2.id > h1.id)
        GROUP BY contact_id;";

      // Increase the max length for group concat.
      CRM_Core_DAO::executeQuery("SET SESSION group_concat_max_len = 1000000;");
      // Nb. the following line is supposed to be the same, it's unclear to me when you would choose one or the other.
      // CRM_Core_DAO::executeQuery("SET @@SESSION.group_concat_max_len = 1000000;");

      // Create the table.
      CRM_Core_DAO::executeQuery($sql, $params);

      // I think adding the primary key after loading the data should be faster than
      // defining a primary key to begin with.
      CRM_Core_DAO::executeQuery("ALTER TABLE temp_mailchimpsync_subscriptions ADD PRIMARY KEY contact_id;");
    }
  }
}
