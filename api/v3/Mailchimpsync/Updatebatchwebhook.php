<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * Mailchimpsync.Updatebatchwebhook API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_mailchimpsync_Updatebatchwebhook_spec(&$spec) {
  $spec['process']['api.required'] = 1;
  $spec['process']['api.options'] = ['add', 'delete'];
  $spec['api_key'] = [
    'api.required' => 1,
    'description' => 'Mailchimp API Key',
  ];
  $spec['id'] = [
    'description' => 'Mailchimp batch webhook ID (required for process=delete)',
  ];
}

/**
 * Mailchimpsync.Updatebatchwebhook API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_Updatebatchwebhook($params) {
  $config = CRM_Mailchimpsync::getConfig();
  if (!isset($config['accounts'][$params['api_key']])) {
    throw new API_Exception('Given API Key is not configured.');
  }
  $api = CRM_Mailchimpsync::getMailchimpApi($params['api_key']);

  if ($params['process'] === 'add') {
    $url = CRM_Mailchimpsync::getBatchWebhookUrl($params['api_key']);
    $config['accounts'][$params['api_key']]['batchWebhook'] = $url;

    $api->post('batch-webhooks', ['body' => ['url' => $url]]);
  }
  elseif ($params['process'] === 'delete') {
    if (empty($params['id'])) {
      throw new API_Exception('ID missing');
    }
    $result = $api->delete('batch-webhooks/' . $params['id']);
  }
  else {
    throw new API_Exception('process must be add|delete');
  }

  $webhooks = $api->get('batch-webhooks')['webhooks'] ?? [];
  $config['accounts'][$params['api_key']]['batchWebhooks'] = $webhooks;
  $config['accounts'][$params['api_key']]['batchWebhookFound'] = in_array($url, array_column($webhooks, 'url'));
  CRM_Mailchimpsync::setConfig($config);
  $returnValues = ['config' => CRM_Mailchimpsync::setConfig($config)];
  return civicrm_api3_create_success($returnValues, $params, 'Mailchimpsync', 'Updatebatchwebhook');
}
