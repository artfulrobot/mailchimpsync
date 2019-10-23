<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * Mailchimpsync.Updateconfig API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_mailchimpsync_Updateconfig_spec(&$spec) {
  $spec['config']['api.required'] = 1;
}

/**
 * Mailchimpsync.Updateconfig API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_Updateconfig($params) {

  $config = json_decode($params['config'], TRUE);
  if (!$config) {
    throw new API_Exception("Failed to parse JSON in 'config' parameter.");
  }

  CRM_Mailchimpsync::setConfig($config);

  return civicrm_api3_create_success(['config' => $config], $params, 'Mailchimpsync', 'Updateconfig');
}
