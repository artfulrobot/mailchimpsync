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

  if ($params['process'] === 'add_batch_webhook') {
    $url = CRM_Mailchimpsync::getBatchWebhookUrl($params['api_key']);
    $config['accounts'][$params['api_key']]['batchWebhook'] = $url;

    $api->post('batch-webhooks', ['body' => ['url' => $url]]);
  }
  elseif ($params['process'] === 'delete_batch_webhook') {
    if (empty($params['id'])) {
      throw new API_Exception('ID missing');
    }
    $result = $api->delete('batch-webhooks/' . $params['id']);
  }
  elseif ($params['process'] === 'add_webhook') {
    if (!$list_id) {
      throw new API_Exception("Missing list_id");
    }
    $url = CRM_Mailchimpsync::getWebhookUrl($params['api_key']);
    $result = $api->post('batch-webhooks', ['body' => ['url' => $url, 'events' => ['subscribe', 'unsubscribe', 'profile', 'upemail', 'cleaned']]]);
    $cleaner = [];
    foreach (['url', 'id', 'sources', 'events'] as $_) {
      $cleaner[$_] = $result[$_] ?? '';
    }
    $config['accounts'][$params['api_key']]['audiences'][$list_id]['webhooks'][] = $cleaner;
    $config['accounts'][$params['api_key']]['audiences'][$list_id]['webhookFound'] = 1;
  }
  elseif ($params['process'] === 'delete_webhook') {
    if (!$list_id) {
      throw new API_Exception("Missing list_id");
    }
    if (empty($params['id'])) {
      throw new API_Exception('ID missing');
    }
    $result = $api->delete("lists/$list_id/webhooks/$params[id]");

    // recalc webhooks.
    $url = CRM_Mailchimpsync::getWebhookUrl($params['api_key']);
    $config['accounts'][$params['api_key']]['audiences'][$list_id]['webhookFound'] = FALSE;
    $new_webhooks = [];
    foreach ($config['accounts'][$params['api_key']]['audiences'][$list_id]['webhooks'] as $_) {
      if ($_['id'] !== $params['id']) {
        $new_webhooks[] = $_;
        $config['accounts'][$params['api_key']]['audiences'][$list_id]['webhookFound'] |= $url === $_['url'];
      }
    }
    $config['accounts'][$params['api_key']]['audiences'][$list_id]['webhooks'] = $new_webhooks;
  }
  else {
    throw new API_Exception('process must be add_batch_webhook|delete_batch_webhook|add_webhook|delete_webhook');
  }

  if (preg_match('/batch/', $params['process'])) {
    $webhooks = $api->get('batch-webhooks')['webhooks'] ?? [];
    $config['accounts'][$params['api_key']]['batchWebhooks'] = $webhooks;
    $config['accounts'][$params['api_key']]['batchWebhookFound'] = in_array($url, array_column($webhooks, 'url'));
  }

  CRM_Mailchimpsync::setConfig($config);
  $returnValues = ['config' => CRM_Mailchimpsync::setConfig($config)];
  return civicrm_api3_create_success($returnValues, $params, 'Mailchimpsync', 'Updatewebhook');
}
