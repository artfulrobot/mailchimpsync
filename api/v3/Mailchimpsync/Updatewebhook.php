<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * Mailchimpsync.Updatewebhook API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_mailchimpsync_Updatewebhook_spec(&$spec) {
  $spec['process']['api.required'] = 1;
  $spec['process']['api.options'] = [
    'add_batch_webhook',
    'delete_batch_webhook',
    'add_webhook',
    'delete_webhook'
  ];
  $spec['api_key'] = [
    'api.required' => 1,
    'description' => 'Mailchimp API Key',
  ];
  $spec['id'] = [
    'description' => 'Mailchimp batch webhook ID (required for process=delete)',
  ];
}

/**
 * Mailchimpsync.Updatewebhook API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_Updatewebhook($params) {
  $config = CRM_Mailchimpsync::getConfig();
  if (!isset($config['accounts'][$params['api_key']])) {
    throw new API_Exception('Given API Key is not configured.');
  }
  $api = CRM_Mailchimpsync::getMailchimpApi($params['api_key']);
  $list_id = $params['list_id'] ?? NULL;
  $type = NULL;

  if ($params['process'] === 'add_batch_webhook') {
    // Create a batch webhook
    $type = 'batch';
    $url = CRM_Mailchimpsync::getBatchWebhookUrl($params['api_key']);
    $api->post('batch-webhooks', ['body' => ['url' => $url]]);
  }
  elseif ($params['process'] === 'delete_batch_webhook') {
    // Delete a batch webhook.
    $type = 'batch';
    if (empty($params['id'])) {
      throw new API_Exception('ID missing');
    }
    $result = $api->delete('batch-webhooks/' . $params['id']);
  }
  elseif ($params['process'] === 'add_webhook') {
    // Add a webhook to a list
    $type = 'audience';
    if (!$list_id) {
      throw new API_Exception("Missing list_id");
    }
    $url = CRM_Mailchimpsync::getWebhookUrl($params['api_key']);
    $result = $api->post("lists/$list_id/webhooks",
      [
        'body' =>
        [
          'url' => $url,
          'events' => [
            'subscribe' => TRUE, 'unsubscribe' =>TRUE, 'profile' =>TRUE,
            'upemail' =>TRUE, 'cleaned' =>TRUE, 'campaign' => FALSE,
          ],
          'sources' => [ 'user' => TRUE, 'admin' =>TRUE, 'api' => FALSE ],
        ],
      ]);
  }
  elseif ($params['process'] === 'delete_webhook') {
    // delete a webhook from an audience
    $type = 'audience';
    if (!$list_id) {
      throw new API_Exception("Missing list_id");
    }
    if (empty($params['id'])) {
      throw new API_Exception('ID missing');
    }
    $result = $api->delete("lists/$list_id/webhooks/$params[id]");
  }
  else {
    throw new API_Exception('process must be add_batch_webhook|delete_batch_webhook|add_webhook|delete_webhook');
  }

  // Now re-read and store the webhooks.
  if ($type === 'batch') {
    $config = CRM_Mailchimpsync::reloadBatchWebhooks($params['api_key']);
  }
  elseif ($type === 'audience') {
    $config = CRM_Mailchimpsync::reloadWebhooks($params['api_key'], $list_id);
  }

  $returnValues = ['config' => $config];
  return civicrm_api3_create_success($returnValues, $params, 'Mailchimpsync', 'Updatewebhook');
}
