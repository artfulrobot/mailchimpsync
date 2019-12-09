<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Mailchimpsync.Updatebatchwebhook API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Mailchimpsync_UpdatebatchwebhookTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;
  use CRM_Mailchimpsync_FixturesTrait;

  /**
   * Set up for headless tests.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp() {
    parent::setUp();
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown() {
    parent::tearDown();
  }

  /**
   *
   */
  public function testCreatingBatchWebhook() {
    $this->createConfig2();
    $api = CRM_Mailchimpsync::getMailchimpApi('mock_account_1');

    $expected_webhook_url = CRM_Mailchimpsync::getBatchWebhookUrl('mock_account_1');
    $result = civicrm_api3('Mailchimpsync', 'updatewebhook', [
      'api_key' => 'mock_account_1',
      'process' => 'add_batch_webhook',
    ]);

    $this->assertCount(1, $api->calls, "Expected an API call.");
    $this->assertEquals(
      [
        'method'  => 'POST',
        'path'    => 'batch-webhooks',
        'options' => ['body' => [ 'url' => $expected_webhook_url ]],
      ], $api->calls[0]);

  }

  /**
   *
   */
  public function testDeleteBatchWebhook() {
    $this->createConfig2();
    $api = CRM_Mailchimpsync::getMailchimpApi('mock_account_1');

    civicrm_api3('Mailchimpsync', 'updatewebhook', [
      'api_key' => 'mock_account_1',
      'process' => 'delete_batch_webhook',
      'id'      => 'some_webhook_id',
    ]);

    $this->assertCount(1, $api->calls, "Expected an API call.");
    $this->assertEquals(
      [
        'method'  => 'DELETE',
        'path'    => 'batch-webhooks/some_webhook_id',
        'options' => [],
      ], $api->calls[0]);

  }

  /**
   *
   */
  public function testCreatingWebhook() {
    $this->createConfig2();
    $api = CRM_Mailchimpsync::getMailchimpApi('mock_account_1');

    $expected_webhook_url = CRM_Mailchimpsync::getWebhookUrl('mock_account_1');

    // Create a webhook on a list.
    $result = civicrm_api3('Mailchimpsync', 'updatewebhook', [
      'api_key' => 'mock_account_1',
      'process' => 'add_webhook',
      'list_id' => 'list_1',
    ]);

    $this->assertCount(1, $api->calls, "Expected an API call.");
    $this->assertEquals(
      [
        'method'  => 'POST',
        'path'    => 'lists/list_1/webhooks',
        'options' => ['body' => [
          'url' => $expected_webhook_url,
          'events' => [
            'subscribe' => TRUE,
            'unsubscribe' => TRUE,
            'profile' => TRUE,
            'upemail' => TRUE,
            'cleaned' => TRUE,
            'campaign' => FALSE,
          ],
          'sources' => [
            'user' => TRUE,
            'admin' => TRUE,
            'api' => FALSE,
          ]
        ]],
      ], $api->calls[0]);

  }

  /**
   *
   */
  public function testDeleteWebhook() {
    $this->createConfig2();
    $api = CRM_Mailchimpsync::getMailchimpApi('mock_account_1');

    civicrm_api3('Mailchimpsync', 'updatewebhook', [
      'api_key' => 'mock_account_1',
      'process' => 'delete_webhook',
      'id'      => 'some_webhook_id',
      'list_id' => 'list_1',
    ]);

    $this->assertCount(1, $api->calls, "Expected an API call.");
    $this->assertEquals(
      [
        'method'  => 'DELETE',
        'path'    => 'lists/list_1/webhooks/some_webhook_id',
        'options' => [],
      ], $api->calls[0]);

  }

}
