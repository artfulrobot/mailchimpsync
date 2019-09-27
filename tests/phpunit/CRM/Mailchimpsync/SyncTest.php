<?php

use CRM_Mailchimpsync_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * General tests
 *
 * @todo need to test situation where we try and fail to subscribe at mailchimp.
 *       could be need to set to pending, or could fail for GDPR deletion.
 *
 *
 * @group headless
 */
class CRM_Mailchimpsync_SyncTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /** @var CRM_Mailchimpsync_MailchimpApiMock */
  public $api;

  /** @var string 2019-09-23 type dates */
  public $a_week_ago;
  public static $already_recreated_db = FALSE;
  public $yesterday;
  public function setUpHeadless() {

    // Set this TRUE after changing schema etc.
    $force_recreate_database = TRUE;
    $force_recreate_database = FALSE;

    if ($force_recreate_database) {
      // If we need to do this, we only do it once.
      if (static::$already_recreated_db == TRUE) {
        $force_recreate_database = FALSE;
      }
      static::$already_recreated_db = TRUE;
    }

    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply($force_recreate_database);
  }

  public function setUp() {
    $this->a_week_ago = date('Y-m-d', strtotime('today - 1 week'));
    $this->yesterday = date('Y-m-d', strtotime('yesterday'));
    // Clean out our sync table (don't use TRUNCATE, doesn't play well with transactional rollback)
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailchimpsync_cache');

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

  /**
   * If a member previously had a contact ID, but that contact Id now no longer
   * exists, or belongs to a deleted contact, we need remove the now useless
   * contact ID from the cache table.
   */
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
  public function testContactsCreated() {
    // Create records without contact id.
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (mailchimp_member_id, mailchimp_list_id, mailchimp_email)
            VALUES(%1, 'list_1', %2)";
    CRM_Core_DAO::executeQuery($sql, [
      1 => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'String'],
      2 => ['contact1@example.com', 'String'],
    ]);

    $contact_2 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test2', 'email' => 'contact2@example.com'])['id'];
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (mailchimp_member_id, mailchimp_list_id, mailchimp_email, civicrm_contact_id)
            VALUES(%1, 'list_1', %2, %3)";
    CRM_Core_DAO::executeQuery($sql, [
      1 => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'String'],
      2 => ['contact2@example.com', 'String'],
      3 => [$contact_2, 'Integer'],
    ]);

    // Create simple config.
    $audience = $this->createConfigFixture1AndGetAudience(TRUE);

    // Create missing contacts
    $created = $audience->createNewContactsFromMailchimp();
    $this->assertEquals(1, $created);

    // Check we have our new contact.
    $email = CRM_Core_DAO::executeQuery('SELECT COUNT(*) FROM civicrm_email WHERE email = %1', [1 => ['contact1@example.com', 'String']])->fetchValue();
    $this->assertEquals(1, $email);

    // Check the contact 2 was not harmed.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_email = 'contact2@example.com';
    if ($bao->find(1)) {
      $this->assertEquals($contact_2, $bao->civicrm_contact_id, "Failed checking that the matched contact was not touched by creating a new one.");
    }
    else {
      $this->fail("Failed to find contact2@example.com");
    }

  }
  /**
   */
  public function testCiviOnly() {
    $audience = $this->createConfigFixture1AndGetAudience(TRUE);

    // Contact 1, create and add into group.
    $contact_1 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test1', 'email' => 'contact1@example.com'])['id'];
    $contacts = [$contact_1];
    CRM_Contact_BAO_GroupContact::addContactsToGroup($contacts, $audience->getSubscriptionGroup());

    // Contact 2, not in the group, but in Mailchimp.
    $contact_2 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test2', 'email' => 'contact2@example.com'])['id'];
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (civicrm_contact_id, mailchimp_member_id, mailchimp_list_id, mailchimp_email)
            VALUES($contact_2, %1, 'list_1', %2)";
    CRM_Core_DAO::executeQuery($sql, [
      1 => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'String'],
      2 => ['contact1@example.com', 'String'],
    ]);

    // Do work.
    $added = $audience->addCiviOnly();

    // Check
    $this->assertEquals(1, $added);

    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    // Check Contact 1 is now in the cache table.
    $bao->civicrm_contact_id = $contact_1;
    $this->assertEquals(1, $bao->count());
    // Check Contact 2 is still (should not have changed!)
    $bao->civicrm_contact_id = $contact_2;
    $this->assertEquals(1, $bao->count());
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

  /**
   *
   * Various tests on reconciling the subscription group.
   *
   * @dataProvider reconciliationProvider
   */
  public function testReconciliation($data) {
    $description = $data['description'] . "\n";
    $mailchimp_status = $data['mailchimp_status'];
    $mailchimp_updated = $data['mailchimp_updated'];
    $civicrm_status = $data['civicrm_status'];
    $civicrm_updated = $data['civicrm_updated'];

    $audience = $this->createConfigFixture1AndGetAudience(TRUE);

    // Create one test contact.
    $contact_1 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test1', 'email' => 'contact1@example.com'])['id'];

    // Create cache record manually for our fixture.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->civicrm_contact_id = $contact_1;
    $bao->mailchimp_list_id = 'list_1';

    if ($mailchimp_status) {
      $bao->mailchimp_status = $mailchimp_status;
      $bao->mailchimp_updated = $mailchimp_updated;
    }
    if ($civicrm_status) {
      $bao->civicrm_status = $civicrm_status;
      $bao->civicrm_updated = $civicrm_updated;
      if ($civicrm_status === 'Added') {
        $bao->subscribeInCiviCRM($audience);
      }
      elseif ($civicrm_status === 'Removed') {
        $bao->unsubscribeInCiviCRM($audience);
      }
      elseif ($civicrm_status === 'Deleted') {
        // First we have to subscribe them.
        $bao->subscribeInCiviCRM($audience);
        // Then unsubscribe them.
        $contacts = [$bao->civicrm_contact_id];
        CRM_Contact_BAO_GroupContact::removeContactsFromGroup(
          $contacts, $audience->getSubscriptionGroup(), 'MCsync', 'Deleted');
        $bao->civicrm_status = 'Deleted';
      }
    }
    $bao->save();

    // Now it's all set up, run reconciliation then test expected outcomes.
    $updates = [];
    $audience->reconcileSubscriptionGroup($updates, $bao);

    $this->assertEquals($data['expected_mailchimp_updates'], $updates, "$description Mailchimp updates differ");

    $gc = new CRM_Contact_BAO_GroupContact();
    $gc->contact_id = $bao->civicrm_contact_id;
    $gc->group_id = $audience->getSubscriptionGroup();

    switch ($data['expected_group_status']) {

    case null:
    case 'Deleted':
      // Expect there to be no group membership for this contact.
      $this->assertEquals(0, $gc->find(), "$description Found group contact record, expected none.");
      break;

    case 'Added':
    case 'Removed':
      $this->assertEquals(1, $gc->find(1), "$description No GroupContact record.");
      $this->assertEquals($data['expected_group_status'], $gc->status, "$description Group contact record wrong.");
      break;

    default:
      throw new Exception("Invalid exepcted_group_status: $data[expected_group_status]");
    }
  }

  /**
   * Provides test cases for testReconciliation
   *
   */
  public function reconciliationProvider() {
    $today = date('Y-m-d') . 'T00:00:00Z';
    return [
      [[
        'description' => "Strange case (should never happen) where contact apparently exists nowhere",
        'civicrm_status' => null,
        'civicrm_updated' => null,
        'mailchimp_status' => null,
        'mailchimp_updated' => null,
        'expected_group_status' => null,
        'expected_mailchimp_updates' => [],
      ]],

      [[
        'description' => "New contact at Mailchimp",
        'civicrm_status' => null,
        'civicrm_updated' => null,
        'mailchimp_status' => 'subscribed',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "New contact at CiviCRM",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $today,
        'mailchimp_status' => null,
        'mailchimp_updated' => null,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => ['status' => 'subscribed'],
      ]],
      [[
        'description' => "Contacts both subscribed, civi later",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $today,
        'mailchimp_status' => 'subscribed',
        'mailchimp_updated' => $this->yesterday,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contacts both subscribed, mailchimp later",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $this->yesterday,
        'mailchimp_status' => 'subscribed',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contacts both unsubscribed, mailchimp later",
        'civicrm_status' => 'Removed',
        'civicrm_updated' => $this->yesterday,
        'mailchimp_status' => 'unsubscribed',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Removed',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contacts both unsubscribed, civi later",
        'civicrm_status' => 'Removed',
        'civicrm_updated' => $today,
        'mailchimp_status' => 'unsubscribed',
        'mailchimp_updated' => $this->yesterday,
        'expected_group_status' => 'Removed',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contact has unsubscribed at Mailchimp, CiviCRM should update",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $this->yesterday,
        'mailchimp_status' => 'unsubscribed',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Removed',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contact has been archived at Mailchimp, CiviCRM should update",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $this->yesterday,
        'mailchimp_status' => 'archived',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Removed',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contact has been cleaned at Mailchimp, CiviCRM should update",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $this->yesterday,
        'mailchimp_status' => 'cleaned',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Removed',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contact has been subscribed again at CiviCRM, mailchimp should update",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $today,
        'mailchimp_status' => 'unsubscribed',
        'mailchimp_updated' => $this->yesterday,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => ['status' => 'subscribed'],
      ]],
      [[
        'description' => "Contact has been subscribed again at CiviCRM, mailchimp should update unarchive",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $today,
        'mailchimp_status' => 'archived',
        'mailchimp_updated' => $this->yesterday,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => ['status' => 'subscribed'],
      ]],
      [[
        'description' => "Contact has been unsubscribed at CiviCRM, mailchimp should update unarchive",
        'civicrm_status' => 'Removed',
        'civicrm_updated' => $today,
        'mailchimp_status' => 'subscribed',
        'mailchimp_updated' => $this->yesterday,
        'expected_group_status' => 'Removed',
        'expected_mailchimp_updates' => ['status' => 'unsubscribed'],
      ]],
      [[
        'description' => "Contact has been subscribed at Mailchimp, CiviCRM should update from Removed",
        'civicrm_status' => 'Removed',
        'civicrm_updated' => $this->yesterday,
        'mailchimp_status' => 'subscribed',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contact has been subscribed at Mailchimp, CiviCRM should update from Deleted",
        'civicrm_status' => 'Deleted',
        'civicrm_updated' => $this->yesterday,
        'mailchimp_status' => 'subscribed',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => [],
      ]],
      [[
        'description' => "Contact was subscribed at CiviCRM, is unsubscribed at Mailchimp and was updated in the same second. CiviCRM should win.",
        'civicrm_status' => 'Added',
        'civicrm_updated' => $today,
        'mailchimp_status' => 'unsubscribed',
        'mailchimp_updated' => $today,
        'expected_group_status' => 'Added',
        'expected_mailchimp_updates' => ['status'=>'subscribed'],
      ]],

    ];
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
