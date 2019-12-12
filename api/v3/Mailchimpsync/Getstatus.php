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

  $prettifySeconds = function ($age) {
    $unit = 's';
    $age = round($age, 1);
    if ($age > 60) {
      $age = round($age/60);
      $unit = 'mins';
    }
    if ($age > 60) {
      $age = round($age/60);
      $unit = 'hours';
    }
    $unit = E::ts($unit);
    return number_format($age) . " $unit";
  };

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

    // Shorthand summary.
    $returnValues[$list_id]['in_sync'] =
      ($returnValues[$list_id]['locks']['fetchAndReconcile'] ?? 'readyToFetch') === 'readyToFetch'
      && $returnValues[$list_id]['stats']['mailchimp_updates_pending'] === 0
      && $returnValues[$list_id]['stats']['to_add_to_mailchimp'] === 0
      && $returnValues[$list_id]['stats']['to_remove_from_mailchimp'] === 0
      && !empty($returnValues[$list_id]['lastSyncTime']);

    $returnValues[$list_id]['lastSyncTimeHuman'] = 'never';
    if (!empty($returnValues[$list_id]['lastSyncTime'])) {
      $returnValues[$list_id]['lastSyncTimeHuman'] = date('H:i:s d M Y', strtotime($returnValues[$list_id]['lastSyncTime']));
    }

    // Calculate execution time for each log entry.
    if (!empty($returnValues[$list_id]['log'])) {

      // Ignore 'already busy' logs.
      $relevant_logs = array_filter($returnValues[$list_id]['logs'],
        function ($_) { return (!preg_match('/^Called but locks say process already busy./', $_['message'])); }
      );

      // Start at last entry.
      $last_time = NULL;
      $last_log_entry = NULL;
      for ($i=count($returnValues[$list_id]['log']) - 1 ; $i>0; $i--) {
        $_ = &$returnValues[$list_id]['log'][$i];
        $_['took'] = '';
        // If this log is relevant
        if (!preg_match('/^Called but locks say process already busy./', $_['message'])) {

          $this_time = strtotime($returnValues[$list_id]['log'][$i]['time']);

          if (!$last_log_entry) {
            // Found the latest log entry.
            $last_log_entry = $_;
          }

          if ($last_time) {
            $_['took'] = $prettifySeconds($last_time - $this_time);
          }
          $last_time = $this_time;
        }
        // Ignore 'already busy' logs.
        unset($_);
      }
    }

    // Check for crashes.
    $returnValues[$list_id]['crashed'] = FALSE;
    if (!(($returnValues[$list_id]['locks']['fetchAndReconcile'] ?? 'readyToFetch') === 'readyToFetch')) {
      // The sync process is not in ready state.
      $returnValues[$list_id]['crashed'] = E::ts('Possibly crashed? no logs but not in ready state.');

      if (!empty($last_log_entry)) {
        $age = time() - strtotime($last_log_entry['time']);
        if ($age < 60*30) {
          // Fine.
          $returnValues[$list_id]['crashed'] = FALSE;
        }
        else {
          $age = $prettifySeconds($age);
          $returnValues[$list_id]['crashed'] = E::ts('The logs have not been updated for about %1 - this is an unusually long time.', [1 => $age]);
        }
      }
    }
  }

  return civicrm_api3_create_success($returnValues, $params, 'Mailchimpsync', 'Getstatus');
  //throw new API_Exception(/*errorMessage*/ 'Everyone knows that the magicword is "sesame"', /*errorCode*/ 1234);
}
