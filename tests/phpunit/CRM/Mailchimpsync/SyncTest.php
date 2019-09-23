<?php

use CRM_Mailchimpsync_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * General tests
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class CRM_Mailchimpsync_SyncTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /** @var CRM_Mailchimpsync_MailchimpApiMock */
  public $api;

  /** @var string 2019-09-23 type dates */
  public $a_week_ago;
  public $yesterday;
  public function setUpHeadless() {

    // Set this TRUE after changing schema etc.
    $force_recreate_database = TRUE;
    $force_recreate_database = FALSE;

    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply($force_recreate_database);
  }

  public function setUp() {
    $this->a_week_ago = date('Y-m-d', strtotime('today - 1 week'));
    $this->yesterday = date('Y-m-d', strtotime('yesterday'));
    // Clean out our sync table.
    CRM_Core_DAO::executeQuery('TRUNCATE civicrm_mailchimpsync_cache');

    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Basic test that we're able to get an API object.
   */
  public function testGetApiWorks() {

    $api = CRM_Mailchimpsync::getMailchimpApi('mock_account_1');
    $this->assertInstanceOf(CRM_Mailchimpsync_MailchimpApiInterface::class, $api);
    $this->assertInstanceOf(CRM_Mailchimpsync_MailchimpApiMock::class, $api);

    // check we get a singleton per API key.
    $api_2 = CRM_Mailchimpsync::getMailchimpApi('mock_account_1');
    $this->assertSame($api, $api_2, "getMailchimpApi should return the same object per API key, but returned different objects.");

    // check we can reset this
    $api_2 = CRM_Mailchimpsync::getMailchimpApi('mock_account_1', TRUE);
    $this->assertNotSame($api, $api_2, "getMailchimpApi should return a new object when called with reset=TRUE but returned the same.");

    // Test that a real-looking API key returns the real API
    $api = CRM_Mailchimpsync::getMailchimpApi('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-us1');
    $this->assertInstanceOf(CRM_Mailchimpsync_MailchimpApiInterface::class, $api);
    $this->assertInstanceOf(CRM_Mailchimpsync_MailchimpApiLive::class, $api);
  }

  /**
   * Check we have our special table that helps us with sync.
   */
  public function testFetchMailchimpData() {

    // Create simple config.
    CRM_Mailchimpsync::setConfig([
      'lists' => [
        'list_1' => [
          'apiKey' => 'mock_account_1',
          'subscriptionGroup' => NULL,
        ],
      ]
    ]);

    // Load the audience
    $audience = CRM_Mailchimpsync_Audience::newFromListId('list_1');

    // Get the audience's API so we can provide fixture data.
    $api = $audience->getMailchimpApi();
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'Wilma', 'lname' => 'Flintstone', 'email' => 'wilma@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Betty', 'lname' => 'Rubble', 'email' => 'betty@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Barney', 'lname' => 'Rubble', 'email' => 'barney@example.com', 'status' => 'unsubscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Pebbles', 'lname' => 'Flintstone', 'email' => 'pebbles@example.com', 'status' => 'transactional', 'last_changed' => $this->a_week_ago ],
        ],
      ],
    ]);

    // Get audience for this list.
    $audience = CRM_Mailchimpsync_Audience::newFromListId('list_1');

    // We do this twice to make sure that existing records are updated;
    // they will exist after the intial call.
    for ($i=0; $i<2; ++$i) {
      $audience->mergeMailchimpData();
      $this->assertExpectedCacheStats([
        'count' => 4,
        'count_mailchimp_status_subscribed' => 2,
        'count_mailchimp_status_unsubscribed' => 1,
        'count_mailchimp_status_transactional' => 1,
        'count_mailchimp_status_pending' => 0,
      ]);
    }

  }

  /**
   * Check we have our special table that helps us with sync.
   */
  public function xtestFetchCiviData() {
    $result = civicrm_api3('Group', 'create', [
      'name'       => "test_list_1",
      'title'      => "test_list_1",
      'group_type' => "Mailing List",
    ]);
    $mailing_group_id = $result['id'];

    // Set up config so we know 'list_1' is supposed to be synced with this new group.
    $config = CRM_Mailchimpsync::getConfig();
    $config['lists']['list_1'] = [
      'subscriptionGroup' => $mailing_group_id,
    ];

    $api->mergeCiviData(['list_id' => 'list_1']);
  }

  // Test helpers.
  public function assertExpectedCacheStats($expected) {
    // Fetch stats.
    $sql = "SELECT mailchimp_status, COUNT(id) count FROM civicrm_mailchimpsync_cache GROUP BY mailchimp_status";
    $stats = CRM_Core_DAO::executeQuery($sql)->fetchMap('mailchimp_status', 'count');
    $total = 0;
    foreach ($stats as $count) { $total += (int) $count; }
    if (isset($expected['count'])) {
      $this->assertEquals($expected['count'], $total, "Expected $expected[count] rows in civicrm_mailchimpsync_cache table, but got $total.");
    }

    foreach (['subscribed', 'unsubscribed', 'cleaned', 'pending', 'transactional', 'archived', 'none'] as $_) {
      if (isset($expected["count_mailchimp_status_$_"])) {
        $expected_count = $expected["count_mailchimp_status_$_"];
        $actual_count = (int) ($stats[$_] ?? 0);
        $this->assertEquals($expected_count,
          $actual_count,
          "Expected $expected_count rows with mailchimp status $_ in civicrm_mailchimpsync_cache table, but got $actual_count.");
      }
    }
  }
}
