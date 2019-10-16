<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * Mailchimpsync.Fetchandreconcile API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_mailchimpsync_Fetchandreconcile_spec(&$spec) {
  $spec['group_id'] = [
    'description' => E::ts('The CiviCRM Group used for tracking audience/list subscriptions. If not provided, process all groups/audiences.')
  ];
  $spec['max_time'] = [
    'description' => E::ts('New jobs will not be started after this many seconds have elapsed. Set to 0 for no limit (good for CLI-driven crons). Defaults to 5 mins.'),
    'api.default' => 300,
  ];
	$spec['id']['api.aliases'] = ['group_id'];
}

/**
 * Mailchimpsync.Fetchandreconcile API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_Fetchandreconcile($params) {

  if (!empty($params['group_id'])) {
    try {
      $audiences = [CRM_Mailchimpsync_Audience::newFromGroupId($params['group_id'])->getListId()];
    }
    catch (\InvalidArgumentException $e) {
      throw new API_Exception($e->getMessage());
    }
  }
  else {
    // All audiences/lists
    $config = CRM_Mailchimpsync::getConfig();
    $audiences = array_keys($config['lists'] ?? []);
  }

  // Determine max time.
  $started_time = time();
  if (!isset($params['max_time'])) {
    // Nothing specified, keep to a safe-ish 5 minutes.
    $stop_time = $started_time + 300;
  }
  else {
    if ($params['max_time'] > 0) {
      // Use time specified.
      $stop_time = $started_time + ((int) $params['max_time']);
    }
    else {
      // No time limit. We'll use 1 day - if it's running longer than this something is surely wrong!
      // (If you want to set a longer limit, just pass in whatever you need.)
      $stop_time = $started_time + 60*60*24;
    }
  }

  // Loop audiences while we're within time limit.
  do {
    $audience = CRM_Mailchimpsync_Audience::newFromListId(array_shift($audiences));
    $audience->fetchAndReconcile([
      'time_limit' => $stop_time - time(), // Remaining time.
    ]);
    $returnValues[] = [
      'list_id'  => $audience->getListId(),
      'group_id' => $audience->getSubscriptionGroup(),
      'status'   => $audience->getStatus(),
    ];

  } while ($audiences && time() < $stop_time);

  return civicrm_api3_create_success($returnValues, $params, 'Mailchimpsync', 'Fetchandreconcile');
}
