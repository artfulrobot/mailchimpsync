<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Mailchimpsync.Fetchaccountinfo API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Mailchimpsync_FetchaccountinfoTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

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
   * Fairly pointless test since it's just mocked data anyhoo, but it may help
   * document what's supposed to be returned.
   *
   */
  public function testApi() {
    $result = civicrm_api3('Mailchimpsync', 'Fetchaccountinfo', ['api_key' => 'mock_account_1']);
    $this->assertArrayHasKey('account_name', $result['values']);
    $this->assertArrayHasKey('email', $result['values']);
    $this->assertArrayHasKey('username', $result['values']);
    $this->assertArrayHasKey('first_name', $result['values']);
    $this->assertArrayHasKey('last_name', $result['values']);
    $this->assertArrayHasKey('audiences', $result['values']);
    $this->assertArrayHasKey('list_1', $result['values']['audiences']);
  }

}
