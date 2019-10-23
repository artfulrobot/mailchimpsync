<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * Mailchimpsync.Fetchaccountinfo API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_mailchimpsync_Fetchaccountinfo_spec(&$spec) {
  $spec['api_key']['api.required'] = 1;
  $spec['api_key']['description'] = 'Mailchimp API key';
}

/**
 * Mailchimpsync.Fetchaccountinfo API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_Fetchaccountinfo($params) {

  $api = CRM_Mailchimpsync::getMailchimpApi($params['api_key']);

  try {
    // Fetch top level account info.
    $result = $api->get('', ['fields' => 'account_name,email,first_name,last_name,username']);

    // Fetch audiences.
    $audiences = $api->get('lists', ['fields' => 'lists.id,lists.name', 'count' => 1000]);

    // Store lists keyed by their IDs.
    $result['audiences'] = [];
    foreach ($audiences['lists'] as $list) {
      $list_id = $list['id'];
      unset($list['id']);
      $result['audiences'][$list_id] = $list;
      // Now fetch interests for each list.
      $interest_cats = $api->get("lists/$list_id/interest-categories", [
          'fields' => 'categories.id,categories.title', 'count' => 1000
        ])['categories'] ?? [];
      foreach ($interest_cats as $interest_cat) {
        $interests = $api->get("lists/$list_id/interest-categories/$interest_cat[id]/interests",
          ['fields' => 'interests.id,interests.name', 'count' => 1000])['interests'] ?? [];
        foreach ($interests as $interest) {
          $result['audiences'][$list_id]['interests'][$interest['id']] =
            "$interest_cat[title]: $interest[name]";
        }
      }
    }

    // Fetch webhooks.
    $webhook_secret = CRM_Mailchimpsync::getBatchWebhookSecret($params['api_key']);
    $result['batchWebhookSecret'] = $webhook_secret;
    $result['batchWebhook'] = CRM_Mailchimpsync::getBatchWebhookUrl($params['api_key'], $webhook_secret);
    $result['batchWebhooks'] = $api->get('batch-webhooks')['webhooks'] ?? [];
    $result['batchWebhookFound'] = in_array($result['batchWebhook'], array_column($result['batchWebhooks'], 'url'));
  }
  catch (CRM_Mailchimpsync_RequestErrorException $e) {
    throw new API_Exception('Failed to access Mailchimp API (4xx error) with given key. Error: ' . $e->getMessage());
  }
  catch (Exception $e) {
    throw new API_Exception('Failed to access Mailchimp API (other error). Error: ' . $e->getMessage());
  }

  return civicrm_api3_create_success($result, $params, 'Mailchimpsync', 'Fetchaccountinfo');
}
