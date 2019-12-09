<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Mailchimpsync.Updateconfig API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Mailchimpsync_UpdateconfigTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
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
   */
  public function testJsonConfigIsAccepted() {

    $group_id = civicrm_api3('Group', 'create', [
      'name'       => "test_list_1",
      'title'      => "test_list_1",
      'group_type' => "Mailing List",
    ])['id'];

    $data = [
        'lists' => [
          'list_1' => [
            'apiKey' => 'mock_account_1',
            'subscriptionGroup' => $group_id,
          ],
        ],
        'accounts' => [
          'mock_account_1' => [
            'audiences' => [
              'list_1' => [ ]
            ],
            'batchWebhookSecret' => 'MockBatchWebhookSecret',
          ]
        ]
      ];
    civicrm_api3('Mailchimpsync', 'updateconfig', [ 'config' => json_encode($data) ]);

    $config = CRM_Mailchimpsync::getConfig();
    $this->assertInternalType('array', $config);
    $this->assertEquals($data, $config);
  }

  /**
   * @expectedException CiviCRM_API3_Exception
   * @expectedExceptionMessage Failed to parse JSON in 'config' parameter.
   */
  public function testInvalidJsonRejected() {
    civicrm_api3('Mailchimpsync', 'updateconfig', [ 'config' => 'invalid json' ]);
  }

}
