<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * Mailchimpsync.Abortsync API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_mailchimpsync_Abortsync_spec(&$spec) {
  $spec['group_id']['description'] = 'Identify which audience/list to abort from given CiviCRM (subscription) group ID';
  $spec['group_id']['api.required'] = TRUE;
}

/**
 * Mailchimpsync.Abortsync API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_Abortsync($params) {

  if (!empty($params['group_id'])) {
    $audience = CRM_Mailchimpsync_Audience::newFromGroupId($params['group_id']);
    $audience->abortSync();
    return civicrm_api3_create_success([], $params, 'Mailchimpsync', 'Abortsync');
  }
  // return civicrm_api3_create_success($returnValues, $params, 'Mailchimpsync', 'Abortsync');
    //throw new API_Exception(/*errorMessage*/ 'Everyone knows that the magicword is "sesame"', /*errorCode*/ 1234);
}
