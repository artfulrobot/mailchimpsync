<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Mailchimpsync.Getstatus API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Mailchimpsync_GetstatusTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
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
   * Simple example test case.
   *
   * Note how the function name begins with the word "test".
   */
  public function testApiExample() {
    // Create Fixture
    $various = $this->batchWebhookSetup();
    $audience = $various->audience;
    $api = $audience->getMailchimpApi();
    $api->setMockMailchimpBatchStatus('123456789a', [
      'id' => '123456789a',
      'status' => 'pending',
      'response_body_url' => 'https://example.com/batch-1-results',
      'completed_at' => date('Y-m-d H:i:s'),
      'submitted_at' => date('Y-m-d H:i:s'),
      'finished_operations' => 0,
      'errored_operations' => 0,
      'total_operations' => 1,
    ]);


    // Run tests.
    $result = civicrm_api3('Mailchimpsync', 'Getstatus', []);
    $this->assertEquals(1, $result['count']);
    $this->assertArrayHasKey('list_1', $result['values']);
    $this->assertEquals('readyToFetch', $result['values']['list_1']['locks']['fetchAndReconcile']);
    $this->assertEquals(FALSE, $result['values']['list_1']['in_sync']);
    foreach ([
        'failed' => 0,
        'subscribed_at_mailchimp' => 0,
        'subscribed_at_civicrm' => 1,
        'to_add_to_mailchimp' => 1,
        'cannot_subscribe' => 0,
        'to_remove_from_mailchimp' => 0,
        'todo' => 0,
        'mailchimp_updates_pending' => 1,
        'mailchimp_updates_unsubmitted' => 0,
      ] as $k=>$v) {
        $this->assertEquals($v , $result['values']['list_1']['stats'][$k]);
    }

    $this->assertThat($result['values']['list_1'], $this->logicalNot($this->arrayHasKey('batches')));

    $result = civicrm_api3('Mailchimpsync', 'Getstatus', ['batches' => 1]);
    $this->assertArrayHasKey('batches', $result['values']['list_1']);

  }

}
