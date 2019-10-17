<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * Mailchimpsync.Getstatus API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_mailchimpsync_Getstatus_spec(&$spec) {
  $spec['batches'] = [
    'description' => 'If set, load batches status from Mailchimp API',
  ];
}

/**
 * Mailchimpsync.Getstatus API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_Getstatus($params) {

  $returnValues = [];
  $batches = [];

  if (!empty($params['batches'])) {
    $batches = CRM_Mailchimpsync::fetchBatches();
  }
  $config = CRM_Mailchimpsync::getConfig();
  foreach ($config['lists'] as $list_id => $details) {
    $audience = CRM_Mailchimpsync_Audience::newFromListId($list_id);
    $returnValues[$list_id] = $audience->getStatus();
    // Add in some other stats.
    $returnValues[$list_id]['stats'] = $audience->getStats();
    if (!empty($batches[$list_id])) {
      $returnValues[$list_id]['batches'] = $batches[$list_id];
      $returnValues[$list_id]['stats']['batch_errored_operations'] = 0;
      $returnValues[$list_id]['stats']['batch_finished_operations'] = 0;
      $returnValues[$list_id]['stats']['batch_total_operations'] = 0;
      foreach ($batches[$list_id] as $batch) {
        foreach (['errored_operations','finished_operations','total_operations'] as $_) {
          $returnValues[$list_id]['stats']["batch_$_"] += $batch[$_];
        }
      }
      $returnValues[$list_id]['stats']['batch_pending_operations'] =
        $returnValues[$list_id]['stats']['batch_total_operations']
        - $returnValues[$list_id]['stats']['batch_finished_operations'];
    }
  }

  return civicrm_api3_create_success($returnValues, $params, 'Mailchimpsync', 'Getstatus');
  //throw new API_Exception(/*errorMessage*/ 'Everyone knows that the magicword is "sesame"', /*errorCode*/ 1234);
}
