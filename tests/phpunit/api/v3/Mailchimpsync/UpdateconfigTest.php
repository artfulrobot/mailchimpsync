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

  /**
   */
  public function testListRemoval() {
    // We need an account, a list, a group, a contact.
    $various = $this->createConfig2();
    $audience = $various->audience;
    $cache_entry = $various->cache_entry;
    // Create a fake batch.
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_list_id = 'list_1';
    $batch->mailchimp_batch_id = 'batch_1';
    $batch->status = 'Completed';
    $batch->save();
    // Create an update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->mailchimpsync_cache_id = $cache_entry->id;
    $update->mailchimp_batch_id = 'batch_1';
    $update->completed = 1;
    $update->data = '';
    $update->save();

    // Now remove the list
    $config = CRM_Mailchimpsync::getConfig();
    unset($config['lists']['list_1']);
    CRM_Mailchimpsync::setConfig($config);

    // Now test things got deleted.

    // There should not be any cache records.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $this->assertEquals(0, $bao->count(), "There are cache records that should have been deleted.");

    // There should not be any cache records.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $this->assertEquals(0, $bao->count(), "There are update records that should have been deleted.");

    // There should not be any batch records.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $this->assertEquals(0, $bao->count(), "There are batch records that should have been deleted.");

    // There should not be any status records.
    $count = CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailchimpsync_status');
    $this->assertEquals(0, $count, "There are status records that should have been deleted.");
  }

  /**
   */
  public function testListRemoval2() {
    // We need an account, a list, a group, a contact.
    $various = $this->createConfig2();
    $audience = $various->audience;
    $cache_entry = $various->cache_entry;
    // Create a fake batch.
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_list_id = 'list_1';
    $batch->mailchimp_batch_id = 'batch_1';
    $batch->status = 'Completed';
    $batch->save();
    // Create an update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->mailchimpsync_cache_id = $cache_entry->id;
    $update->mailchimp_batch_id = 'batch_1';
    $update->completed = 1;
    $update->data = '';
    $update->save();

    $config = CRM_Mailchimpsync::getConfig();
    // 2nd group
    $group_id = civicrm_api3('Group', 'create', [
      'name'       => "test_list_2",
      'title'      => "test_list_2",
      'group_type' => "Mailing List",
    ])['id'];
    // We need a 2nd list
    $config['lists']['list_2'] = [
      'apiKey' => 'mock_account_1',
      'subscriptionGroup' => $group_id,
    ];
    $config['accounts']['mock_account_1']['audiences']['list_2'] = [];
    CRM_Mailchimpsync::setConfig($config);

    // Create a test contact.
    $audience_2 = CRM_Mailchimpsync_Audience::newFromListId('list_2');
    $contact_2 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test2', 'last_name' => 'test2last', 'email' => 'contact2@example.com'])['id'];
    // Create cache record manually for our fixture.
    $cache_entry_2 = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_entry_2->civicrm_contact_id = $contact_2;
    $cache_entry_2->mailchimp_list_id = 'list_2';
    $cache_entry_2->subscribeInCiviCRM($audience_2);
    $cache_entry_2->save();
    // Create a fake batch.
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_list_id = 'list_2';
    $batch->mailchimp_batch_id = 'batch_2';
    $batch->status = 'Completed';
    $batch->save();
    // Create an update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->mailchimpsync_cache_id = $cache_entry_2->id;
    $update->mailchimp_batch_id = 'batch_2';
    $update->completed = 1;
    $update->data = '';
    $update->save();


    // Now remove list_1
    unset($config['lists']['list_1']);
    CRM_Mailchimpsync::setConfig($config);

    // Now test things got deleted.

    // There should not be any cache records.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_list_id = 'list_1';
    $this->assertEquals(0, $bao->count(), "There are cache records that should have been deleted.");

    // There should not be any cache records.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $bao->mailchimpsync_cache_id = $cache_entry->id;
    $this->assertEquals(0, $bao->count(), "There are update records that should have been deleted.");

    // There should not be any batch records.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $bao->mailchimp_list_id = 'list_1';
    $this->assertEquals(0, $bao->count(), "There are batch records that should have been deleted.");

    // There should not be any status records. @todo
    $count = CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailchimpsync_status');
    $this->assertEquals(0, $count, "There are status records that should have been deleted.");

    // Check that things are still there that should be.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_list_id = 'list_2';
    $this->assertEquals(1, $bao->count(), "Missing cache entry2");

    // There should not be any cache records.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $bao->mailchimpsync_cache_id = $cache_entry_2->id;
    $this->assertEquals(1, $bao->count(), "Missing update");

    // There should not be any batch records.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $bao->mailchimp_list_id = 'list_2';
    $this->assertEquals(1, $bao->count(), "Mising batch");

  }

}
