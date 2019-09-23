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
  public function testMergeMailchimpData() {

    // Create simple config.
    $audience = $this->createConfigFixture1AndGetAudience();

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

  public function testDeletedContactsDetected() {
    // Create two contacts.
    $contact_1 = civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test1'])['id'];
    $contact_2 = civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test2'])['id'];

    // Create records.
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (mailchimp_member_id, mailchimp_list_id, civicrm_contact_id, mailchimp_email)
            VALUES(%1, 'list_1', %2, %3)";

    CRM_Core_DAO::executeQuery($sql, [
      1 => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'String'],
      2 => [$contact_1, 'Integer'],
      3 => ['contact1@example.com', 'String'],
    ]);
    CRM_Core_DAO::executeQuery($sql, [
      1 => ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'String'],
      2 => [$contact_2, 'Integer'],
      3 => ['contact2@example.com', 'String'],
    ]);

    // Delete contact 2
    civicrm_api3('Contact', 'delete', ['id' => $contact_2]);

    // Create simple config.
    $audience = $this->createConfigFixture1AndGetAudience();

    $affected = $audience->removeInvalidContactIds();
    $this->assertEquals(1, $affected, "Expected removeInvalidContactIds to remove one deleted contact.");

    // Fully delete contact 1
    civicrm_api3('Contact', 'delete', ['id' => $contact_1, 'skip_undelete'=>1]);
    $affected = $audience->removeInvalidContactIds();
    $this->assertEquals(0, $affected, "Expected removeInvalidContactIds to remove one fully deleted contact.");
  }
  public function testContactsMatchedByEmail() {
    $default_stats = [
      'found_by_single_email'                           => 0,
      'used_first_undeleted_contact_in_group'           => 0,
      'used_first_undeleted_contact_with_group_history' => 0,
      'used_first_undeleted_contact'                    => 0,
      'remaining'                                       => 0,
    ];

    // Create contact.
    $contact_1 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test1', 'email' => 'contact1@example.com'])['id'];

    // Create records without contact id.
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (mailchimp_member_id, mailchimp_list_id, mailchimp_email)
            VALUES(%1, 'list_1', %2)";
    CRM_Core_DAO::executeQuery($sql, [
      1 => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'String'],
      2 => ['contact1@example.com', 'String'],
    ]);

    // Create simple config.
    $audience = $this->createConfigFixture1AndGetAudience(TRUE);

    // Populate - should find our contact.
    $stats = $audience->populateMissingContactIds();
    $this->assertEquals(['found_by_single_email' => 1] + $default_stats,
      $stats, "Failed to populate contact from email (test 1)");
    // Check it found the right contact.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_email = 'contact1@example.com';
    $this->assertEquals(1, $bao->find(1), "Failed to find contact1 (test 1)");
    $this->assertEquals($contact_1, $bao->civicrm_contact_id, "Failed to populate contact_id (test 1)");


    //
    // Now reset the cache, add a 2nd duplicate email to the same contact and retry.
    CRM_Core_DAO::executeQuery('UPDATE civicrm_mailchimpsync_cache SET civicrm_contact_id = NULL');
    // This doesn't work: $bao->civicrm_contact_id = NULL; $bao->save();
    $email2 = civicrm_api3('Email', 'create', ['email' => 'contact1@example.com', 'contact_id' => $contact_1]);
    // Populate - should still find our contact.
    $stats = $audience->populateMissingContactIds();
    $this->assertEquals([
      'found_by_single_email' => 1,
      ] + $default_stats
      , $stats, "Failed to populate contact from email (test 2)");
    // Check it found the right contact.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_email = 'contact1@example.com';
    $this->assertEquals(1, $bao->find(1), "Failed to find contact1 (test 2)");
    $this->assertEquals($contact_1, $bao->civicrm_contact_id, "Failed to populate contact_id (test 2)");

    //
    // Attribute the email to two different contacts, but have the first one in the group.
    //
    $contact_2 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test2', 'email' => 'contact1@example.com'])['id'];
    CRM_Contact_BAO_GroupContact::addContactsToGroup([$contact_1], $audience->getSubscriptionGroup());
    CRM_Core_DAO::executeQuery('UPDATE civicrm_mailchimpsync_cache SET civicrm_contact_id = NULL');
    $stats = $audience->populateMissingContactIds();
    $this->assertEquals(['used_first_undeleted_contact_in_group' => 1] + $default_stats, $stats,
      "Expected to have found contact 1 because they were in the group whereas contact 2 was not.");
    // Check it found the right contact.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_email = 'contact1@example.com';
    $this->assertEquals(1, $bao->find(1), "Failed to find contact1 (test 3)");
    $this->assertEquals($contact_1, $bao->civicrm_contact_id, "Failed to populate contact_id (test 3)");

    //
    // Email still owned by 2 contacts, but one *used* to be in the group - expect to pick that one.
    //
    $contacts = [$contact_1];
    CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contacts, $audience->getSubscriptionGroup(), 'Admin', 'Removed');
    CRM_Core_DAO::executeQuery('UPDATE civicrm_mailchimpsync_cache SET civicrm_contact_id = NULL');
    $stats = $audience->populateMissingContactIds();
    $this->assertEquals(['used_first_undeleted_contact_with_group_history' => 1] + $default_stats, $stats,
      "Expected to have found contact 1 because they have a subscription history.");
    // Check it found the right contact.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_email = 'contact1@example.com';
    $this->assertEquals(1, $bao->find(1), "Failed to find contact1 (test 4)");
    $this->assertEquals($contact_1, $bao->civicrm_contact_id, "Failed to populate contact_id (test 4)");

    //
    // Completely remove contact 1 and recreate so that neither contact 1 nor 2
    // has any subscription history but both share teh email.
    //
    // Algorithm should now choose contact 2 which will have lower ID.
    //
    CRM_Core_DAO::executeQuery('UPDATE civicrm_mailchimpsync_cache SET civicrm_contact_id = NULL');
    civicrm_api3('Contact', 'delete', ['id' => $contact_1, 'skip_undelete'=>1]);
    $contact_1 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test1', 'email' => 'contact1@example.com'])['id'];
    $stats = $audience->populateMissingContactIds();
    $this->assertEquals(['used_first_undeleted_contact' => 1] + $default_stats, $stats,
      "Expected to have found first contact.");
    // Check it found the right contact.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_email = 'contact1@example.com';
    $this->assertEquals(1, $bao->find(1), "Failed to find contact1 (test 5)");
    $this->assertEquals($contact_2, $bao->civicrm_contact_id, "Failed to populate contact_id (test 5)");

    // Finally, a contact at mailchimp has an email we don't know.
    CRM_Core_DAO::executeQuery('UPDATE civicrm_mailchimpsync_cache SET civicrm_contact_id = NULL, mailchimp_email = "contact3@example.com";');
    $stats = $audience->populateMissingContactIds();
    $this->assertEquals(['remaining' => 1] + $default_stats, $stats,
      "Expected to have one contact remaining");

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
      'apiKey'            => 'mock_account_1',
      'subscriptionGroup' => $mailing_group_id,
    ];

    //$api->mergeCiviData([]);
  }

  // Test helpers.
  /**
   * Set up simple config, return an audience for it.
   *
   * @param return CRM_Mailchimpsync_Audience
   */
  protected function createConfigFixture1AndGetAudience($with_group = FALSE) {
    if ($with_group) {
      $group_id = civicrm_api3('Group', 'create', [
        'name'       => "test_list_1",
        'title'      => "test_list_1",
        'group_type' => "Mailing List",
      ])['id'];
    }
    else {
      $group_id = NULL;
    }
    CRM_Mailchimpsync::setConfig([
      'lists' => [
        'list_1' => [
          'apiKey' => 'mock_account_1',
          'subscriptionGroup' => $group_id,
        ],
      ]
    ]);
    $audience = CRM_Mailchimpsync_Audience::newFromListId('list_1');
    return $audience;
  }
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
    public function dumpTables() {
      print "\nContacts: \n" . json_encode(CRM_Core_DAO::executeQuery("SELECT id, first_name FROM civicrm_contact ORDER BY id")->fetchAll()) . "\n";
      print "Emails: \n" . json_encode(CRM_Core_DAO::executeQuery("SELECT id, contact_id, email FROM civicrm_email ORDER BY contact_id")->fetchAll()) . "\n";
      print "Cache: \n" . json_encode(CRM_Core_DAO::executeQuery("SELECT mailchimp_email, civicrm_contact_id FROM civicrm_mailchimpsync_cache")->fetchAll()) . "\n";
    }

}
