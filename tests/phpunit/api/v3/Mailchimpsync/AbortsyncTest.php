<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Mailchimpsync.Abortsync API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Mailchimpsync_AbortsyncTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
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
   */
  public function testAbortSync() {
    $various = $this->batchWebhookSetup();
    $audience = $various->audience;

    // Adjust the mock response - we need a live batch to test.
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

    // Abort the sync.
    civicrm_api3('Mailchimpsync', 'abortsync', ['group_id' => $audience->getSubscriptionGroup()]);

    // Check that batches/delete api call was made.
    $this->assertEmpty($api->mock_mailchimp_batch_status);

    // Check that our copy of the batch has its status set to 'aborted'
    $dao = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $this->assertEquals(1, $dao->find(1));
    $this->assertEquals('aborted', $dao->status);

    // Check that the cache record is now set to 'fail'.
    $dao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $dao->id = $various->cache_entry->id;
    $this->assertEquals(1, $dao->find(1));
    $this->assertEquals('fail', $dao->sync_status);

    // Check that the update is marked completed/aborted
    $dao = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $dao->id = $various->update_id;
    $this->assertEquals(1, $dao->find(1));
    $this->assertEquals(1, $dao->completed);
    $this->assertEquals('Sync was aborted', $dao->error_response);

    // Check that the status is reset.
    $status = $audience->getStatus();
    $this->assertEquals('readyToFetch', $status['locks']['fetchAndReconcile'] ?? 'MISSING');
    $this->assertEquals(0, $status['fetch']['offset'] ?? 'MISSING');

  }

}
