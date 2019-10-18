<?php
/**
 * Main helper class.
 *
 * @licence AGPL-3
 * @copyright Rich Lott / Artful Robot
 */

class CRM_Mailchimpsync
{
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
    return Civi::settings()->get('mailchimpsync_config');
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
}
