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
class CRM_Mailchimpsync_SyncTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use CRM_Mailchimpsync_FixturesTrait;

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
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailchimpsync_batch');
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailchimpsync_update');
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailchimpsync_status');

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
  public function testMergeMailchimpDataSimple() {

    // Create simple config.
    $audience = $this->createConfigFixture1AndGetAudience();

    // Get the audience's API so we can provide fixture data.
    $api = $audience->getMailchimpApi();
    $this->setMockSubscriberData1($api);

    // We do this twice to make sure that existing records are updated if they exist.
    // i.e. the first call will find some contacts not in civi and will add them,
    // then the second call should find that those contacts already exist. We
    // check that the counts are as expected.
    foreach ([1, 2] as $i) {
      $audience->mergeMailchimpData();
      $this->assertExpectedCacheStats([
        'count' => 4,
        'count_mailchimp_status_subscribed' => 2,
        'count_mailchimp_status_unsubscribed' => 1,
        'count_mailchimp_status_transactional' => 1,
        'count_mailchimp_status_pending' => 0,
      ]);
      $status = $audience->getStatus();
      $this->assertEquals('readyToFixContactIds', $status['locks']['fetchAndReconcile'], "On pass $i got wrong status.");
    }

  }

  /**
   * Check members all fetched if it requires 2 batches.
   */
  public function testMergeMailchimpDataMultipleRuns() {

    // Create simple config.
    $audience = $this->createConfigFixture1AndGetAudience();

    // Get the audience's API so we can provide fixture data.
    $api = $audience->getMailchimpApi();
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'Wilma'  , 'lname' => 'Flintstone', 'email' => 'wilma@example.com'  , 'status' => 'subscribed'   , 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Betty'  , 'lname' => 'Rubble'    , 'email' => 'betty@example.com'  , 'status' => 'subscribed'   , 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Barney' , 'lname' => 'Rubble'    , 'email' => 'barney@example.com' , 'status' => 'unsubscribed' , 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Pebbles', 'lname' => 'Flintstone', 'email' => 'pebbles@example.com', 'status' => 'transactional', 'last_changed' => $this->a_week_ago ],
        ],
      ],
    ]);
    $audience = CRM_Mailchimpsync_Audience::newFromListId('list_1');

    // Limit api's batch fetch.
    $api->max_members_to_fetch = 2;

    // It should take 2 runs to populate.
    $audience->mergeMailchimpData(['max_time' => 0]);
    $this->assertExpectedCacheStats([
      'count' => 2,
      'count_mailchimp_status_subscribed' => 2,
      'count_mailchimp_status_unsubscribed' => 0,
      'count_mailchimp_status_transactional' => 0,
      'count_mailchimp_status_pending' => 0,
    ]);
    $status = $audience->getStatus();
    $this->assertEquals('readyToFetch', $status['locks']['fetchAndReconcile']);
    $audience->mergeMailchimpData(['max_time' => 0]);
    $this->assertExpectedCacheStats([
      'count' => 4,
      'count_mailchimp_status_subscribed' => 2,
      'count_mailchimp_status_unsubscribed' => 1,
      'count_mailchimp_status_transactional' => 1,
      'count_mailchimp_status_pending' => 0,
    ]);
    $status = $audience->getStatus();
    $this->assertEquals('readyToFixContactIds', $status['locks']['fetchAndReconcile']);

  }

  /**
   * Check fetchAndReconcile does not run if there's a lock in place for that
   * list; check that for other lists it still works.
   */
  public function testMergeMailchimpDataDoesNotRunIfLocked() {

    // Create simple config.
    $audiences = $this->createConfigFixtureAndGetAudience(2, TRUE);

    // Get the audience's API so we can provide fixture data. (it's the same
    // account, so we can use either audience)
    $api = $audiences[1]->getMailchimpApi();
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'Wilma', 'lname' => 'Flintstone', 'email' => 'wilma@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Betty', 'lname' => 'Rubble', 'email' => 'betty@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Barney', 'lname' => 'Rubble', 'email' => 'barney@example.com', 'status' => 'unsubscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Pebbles', 'lname' => 'Flintstone', 'email' => 'pebbles@example.com', 'status' => 'transactional', 'last_changed' => $this->a_week_ago ],
        ],
      ],
      'list_2' => [
        'members' => [
          [ 'fname' => 'Bam Bam', 'lname' => 'Flintstone', 'email' => 'bambam@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
        ],
      ],
    ]);

    // Put a 'busy' lock in place for list 1
    $this->assertTrue($audiences[1]->attemptToObtainLock([
      'for' => 'fetchAndReconcile',
      'to'  => 'busy',
      'if'  => 'readyToFetch',
    ]), "Expected to be able to obtain a lock.");

    // Simulate a 2nd async process
    $audiences[1]->fetchAndReconcile([]);
    $status = $audiences[1]->getStatus();
    $this->assertEquals('Called but locks say process already busy. Will not do anything.', $status['log'][0]['message'] ?? '');
    $this->assertEquals('busy', $status['locks']['fetchAndReconcile']);


    // Now do a fetch and reconcile on list 2 - this should NOT be blocked.
    $audiences[2]->fetchAndReconcile([]);
    $status = $audiences[2]->getStatus();
    $this->assertEquals('fetchAndReconcile process is complete.', end($status['log'])['message'] ?? '');
    $this->assertEquals('readyToFetch', $status['locks']['fetchAndReconcile']);

  }

  /**
   * Sanity test: check that sync on one list does not affect another.
   */
  public function testAudienceIsolation() {

    // Create simple config.
    $audiences = $this->createConfigFixtureAndGetAudience(2, TRUE);

    // Get the audience's API so we can provide fixture data. (it's the same
    // account, so we can use either audience)
    $api = $audiences[1]->getMailchimpApi();
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'Wilma', 'lname' => 'Flintstone', 'email' => 'wilma@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Betty', 'lname' => 'Rubble', 'email' => 'betty@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Barney', 'lname' => 'Rubble', 'email' => 'barney@example.com', 'status' => 'unsubscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Pebbles', 'lname' => 'Flintstone', 'email' => 'pebbles@example.com', 'status' => 'transactional', 'last_changed' => $this->a_week_ago ],
        ],
      ],
      'list_2' => [
        'members' => [
          [ 'fname' => 'Bam Bam', 'lname' => 'Flintstone', 'email' => 'bambam@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
        ],
      ],
    ]);

    $audience1hash = $this->getAudienceHash($audiences[1]);
    $audience2hash = $this->getAudienceHash($audiences[2]);

    // Reconile list 1
    $audiences[1]->fetchAndReconcile([]);
    // Check it changed (really a test that we're hashing the right things)
    $this->assertNotEquals($audience1hash, $this->getAudienceHash($audiences[1]));
    // Check list 2 unchanged.
    $this->assertEquals($audience2hash, $this->getAudienceHash($audiences[2]));

    // Take new hash of audience 1
    $audience1hash = $this->getAudienceHash($audiences[1]);
    $audiences[2]->fetchAndReconcile([]);
    // Check it changed (really a test that we're hashing the right things)
    $this->assertNotEquals($audience2hash, $this->getAudienceHash($audiences[1]));
    // Check list 1 unchanged.
    $this->assertEquals($audience1hash, $this->getAudienceHash($audiences[1]));

    // Now reverse the list contents at Mailchimp - so a sync would now alter contacts previously on the other lists.
    // Of course, this should not make any difference, but this is a sanity check test.
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'Bam Bam', 'lname' => 'Flintstone', 'email' => 'bambam@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
        ],
      ],
      'list_2' => [
        'members' => [
          [ 'fname' => 'Wilma', 'lname' => 'Flintstone', 'email' => 'wilma@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Betty', 'lname' => 'Rubble', 'email' => 'betty@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Barney', 'lname' => 'Rubble', 'email' => 'barney@example.com', 'status' => 'unsubscribed', 'last_changed' => $this->a_week_ago ],
          [ 'fname' => 'Pebbles', 'lname' => 'Flintstone', 'email' => 'pebbles@example.com', 'status' => 'transactional', 'last_changed' => $this->a_week_ago ],
        ],
      ],
    ]);

    $audience1hash = $this->getAudienceHash($audiences[1]);
    $audience2hash = $this->getAudienceHash($audiences[2]);

    // Reconile list 1
    $audiences[1]->fetchAndReconcile([]);
    // Check it changed (really a test that we're hashing the right things)
    $this->assertNotEquals($audience1hash, $this->getAudienceHash($audiences[1]));
    // Check list 2 unchanged.
    $this->assertEquals($audience2hash, $this->getAudienceHash($audiences[2]));

    // Take new hash of audience 1
    $audience1hash = $this->getAudienceHash($audiences[1]);
    $audiences[2]->fetchAndReconcile([]);
    // Check it changed (really a test that we're hashing the right things)
    $this->assertNotEquals($audience2hash, $this->getAudienceHash($audiences[1]));
    // Check list 1 unchanged.
    $this->assertEquals($audience1hash, $this->getAudienceHash($audiences[1]));

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

    // Create cache record for contact 1, with Mailchimp ID
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (mailchimp_member_id, mailchimp_list_id, civicrm_contact_id, mailchimp_email)
            VALUES(%1, 'list_1', %2, %3)";
    CRM_Core_DAO::executeQuery($sql, [
      1 => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'String'],
      2 => [$contact_1, 'Integer'],
      3 => ['contact1@example.com', 'String'],
    ]);

    // Create cache record for contact 2, without Mailchimp ID
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (mailchimp_list_id, civicrm_contact_id)
            VALUES('list_1', %1)";
    CRM_Core_DAO::executeQuery($sql, [ 1 => [$contact_2, 'Integer'] ]);

    // Soft Delete contacts
    civicrm_api3('Contact', 'delete', ['id' => $contact_2]);
    civicrm_api3('Contact', 'delete', ['id' => $contact_1]);

    // Create simple config.
    $audience = $this->createConfigFixture1AndGetAudience();

    // Do work we want to test:
    $affected = $audience->removeInvalidContactIds();

    // check expectations.
    $this->assertEquals(2, $affected['updated'], "Expected removeInvalidContactIds to remove two (soft) deleted contacts.");
    $this->assertEquals(1, $affected['deleted'], "Expected removeInvalidContactIds to delete an empty cache row");

    //
    // This next bit is not really a test of the removeInvalidContactIds.
    // It's here because once upon a time we did not use FKs and so this was needed.
    // Now it's just left in for the note.
    //
    // Fully delete contact 1 - the FK should delete the cahce record for us.
    civicrm_api3('Contact', 'delete', ['id' => $contact_1, 'skip_undelete'=>1]);
    // Do work we want to test:
    $affected = $audience->removeInvalidContactIds();
    // check expectations
    $this->assertEquals(0, $affected['updated'], "Expected removeInvalidContactIds not to remove any contact_ids from the cache table.");
    $this->assertEquals(0, $affected['deleted'], "Expected removeInvalidContactIds not to delete any cache rows");

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
      array_intersect_key($stats, $default_stats), "Failed to populate contact from email (test 1)");
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
      , array_intersect_key($stats, $default_stats), "Failed to populate contact from email (test 2)");
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
    $this->assertEquals(['used_first_undeleted_contact_in_group' => 1] + $default_stats, array_intersect_key($stats, $default_stats),
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
    $this->assertEquals(['used_first_undeleted_contact_with_group_history' => 1] + $default_stats,
      array_intersect_key($stats, $default_stats),
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
    $this->assertEquals(['used_first_undeleted_contact' => 1] + $default_stats,
      array_intersect_key($stats, $default_stats),
      "Expected to have found first contact.");
    // Check it found the right contact.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_email = 'contact1@example.com';
    $this->assertEquals(1, $bao->find(1), "Failed to find contact1 (test 5)");
    $this->assertEquals($contact_2, $bao->civicrm_contact_id, "Failed to populate contact_id (test 5)");

    // Finally, a contact at mailchimp has an email we don't know.
    CRM_Core_DAO::executeQuery('UPDATE civicrm_mailchimpsync_cache SET civicrm_contact_id = NULL, mailchimp_email = "contact3@example.com";');
    $stats = $audience->populateMissingContactIds();
    $this->assertEquals(['remaining' => 1] + $default_stats,
      array_intersect_key($stats, $default_stats),
      "Expected to have one contact remaining");

  }
  public function testContactsCreated() {
    // Create records without contact id.
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (mailchimp_member_id, mailchimp_list_id, mailchimp_email, mailchimp_status)
            VALUES(%1, 'list_1', %2, 'subscribed')";
    CRM_Core_DAO::executeQuery($sql, [
      1 => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'String'],
      2 => ['contact1@example.com', 'String'],
    ]);

    $contact_2 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test2', 'email' => 'contact2@example.com'])['id'];
    $sql = "INSERT INTO civicrm_mailchimpsync_cache (mailchimp_member_id, mailchimp_list_id, mailchimp_email, civicrm_contact_id, mailchimp_status)
            VALUES(%1, 'list_1', %2, %3, 'subscribed')";
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
   */
  public function testAddCiviOnlyDoesNotAddDeleted() {
    $audience = $this->createConfigFixture1AndGetAudience(TRUE);

    // Contact 1, create and add into group.
    $contact_1 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test1', 'email' => 'contact1@example.com'])['id'];
    $contacts = [$contact_1];
    CRM_Contact_BAO_GroupContact::addContactsToGroup($contacts, $audience->getSubscriptionGroup());

    // Delete the contact
    civicrm_api3('Contact', 'delete', ['id' => $contact_1]);

    // Do work.
    $added = $audience->addCiviOnly();

    // Check
    $this->assertEquals(0, $added, "Should not add deleted contacts");
  }
  /**
   *
   * Various tests on reconciling the subscription group.
   *
   * @dataProvider reconcileSubscriptionGroupDataProvider
   */
  public function testReconcileSubscriptionGroup($data) {
    $description = $data['description'] . "\n";
    $mailchimp_status = $data['mailchimp_status'];
    $mailchimp_updated = $data['mailchimp_updated'];
    $civicrm_status = $data['civicrm_status'];
    $civicrm_updated = $data['civicrm_updated'];

    $audience = $this->createConfigFixture1AndGetAudience(TRUE);

    // Create one test contact.
    $contact_1 = (int) civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'test1',
      'email' => 'contact1@example.com'])['id'];

    // Create cache record manually for our fixture.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->civicrm_contact_id = $contact_1;
    $bao->mailchimp_list_id = 'list_1';

    if ($mailchimp_status) {
      $bao->mailchimp_status = $mailchimp_status;
      $bao->mailchimp_updated = $mailchimp_updated;
    }

    $bao->civicrm_groups = $civicrm_status
      ? $audience->getSubscriptionGroup() . ";$civicrm_status;$civicrm_updated"
      : NULL;

    if ($civicrm_status) {
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
      }

      // Allow overriding the civicrm_updated for test.
      /*
      CRM_Core_DAO::executeQuery(
        'UPDATE civicrm_subscription_history
          SET date = %1
          WHERE contact_id = %2 AND group_id = %3',
        [
          1 => [$civicrm_updated, 'String'],
          2 => [$bao->civicrm_contact_id, 'Integer'],
          3 => [$audience->getSubscriptionGroup(), 'Integer']
        ]);
      $bao->civicrm_updated = $civicrm_updated;
      */

    }
    $bao->save();

    // Now it's all set up, run reconciliation then test expected outcomes.
    $updates = [];
    $subs = $audience->parseSubs($bao->mailchimp_updated, $bao->civicrm_groups);
    $audience->reconcileSubscriptionGroup($updates, $bao, $subs);

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
   *
   * https://github.com/artfulrobot/mailchimpsync/issues/9
   *
   * @dataProvider reconcileIssue9DataProvider
   */
  public function testReconcileIssue9($description, $mailchimp_status_1, $mailchimp_status_2, $civicrm_status, $expected_civicrm_status) {
    $audience = $this->createConfigFixture1AndGetAudience(TRUE);

    // Create one test contact.
    $contact_1 = (int) civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name'   => 'test1',
      'email'        => 'contact1@example.com'])['id'];

    // Add a 2nd email for this contact.
    civicrm_api3('Email', 'create', ['email' => 'email2@example.com', 'contact_id' => $contact_1]);

    // Create cache records manually for our fixture.
    $today = date('Y-m-d') . 'T00:00:00Z';

    // Cache record for original email - removed today at Mailchimp
    // We set it as added yesterday in Civi so Mailchimp is always right.
    $cache_1 = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_1->civicrm_contact_id = $contact_1;
    $cache_1->mailchimp_list_id = 'list_1';
    $cache_1->mailchimp_email = 'contact1@example.com';
    $cache_1->mailchimp_member_id = md5('contact1@example.com');
    $cache_1->mailchimp_updated = $today;
    $cache_1->mailchimp_status = $mailchimp_status_1;
    $cache_1->sync_status = 'todo';
    $cache_1->civicrm_groups = $audience->getSubscriptionGroup() . ";$civicrm_status;$this->yesterday";
    $cache_1->save();
    // Use this cache record to add them to the group.
    $cache_1->subscribeInCiviCRM($audience);
    if ($civicrm_status === 'Removed') {
      $contacts = [$contact_1];
      // Record as Removed at CiviCRM.
      CRM_Contact_BAO_GroupContact::removeContactsFromGroup(
        $contacts, $audience->getSubscriptionGroup(), 'test', 'Removed');
    }

    // Create 2nd cache record for 2nd email, subscribed today at MC.
    $cache_2 = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_2->civicrm_contact_id = $contact_1;
    $cache_2->mailchimp_list_id = 'list_1';
    $cache_2->mailchimp_email = 'email2@example.com';
    $cache_2->mailchimp_member_id = md5('email2@example.com');
    $cache_2->mailchimp_updated = $today;
    $cache_2->mailchimp_status = $mailchimp_status_2;
    $cache_2->sync_status = 'todo';
    $cache_2->civicrm_groups = $audience->getSubscriptionGroup() . ";$civicrm_status;$this->yesterday";
    $cache_2->save();

    // Now it's all set up, run reconciliation, first for cache_1, then 2.
    $updates = [];
    $subs = $audience->parseSubs($cache_1->mailchimp_updated, $cache_1->civicrm_groups);
    $audience->reconcileSubscriptionGroup($updates, $cache_1, $subs);
    $this->assertEquals([], $updates, "Expected no updates to Mailchimp.");
    $updates = [];
    // ... 2nd cache record.
    $subs = $audience->parseSubs($cache_2->mailchimp_updated, $cache_2->civicrm_groups);
    $audience->reconcileSubscriptionGroup($updates, $cache_2, $subs);
    $this->assertEquals([], $updates, "Expected no updates to Mailchimp.");

    // We expect to find that the contact is $expected_civicrm_status
    $gc = new CRM_Contact_BAO_GroupContact();
    $gc->contact_id = $contact_1;
    $gc->group_id = $audience->getSubscriptionGroup();

    $this->assertEquals(1, $gc->find(1), "No GroupContact record.");
    $this->assertEquals($expected_civicrm_status, $gc->status, "Expected contact to be $expected_civicrm_status to group.");
  }

  /**
   * Data provider for testReconcileIssue9
   *
   */
  public function reconcileIssue9DataProvider() {
    return [
      // These tests passed before issue #9 was handled, tests exist to check regressions.
      [
        'unsubscribed/unsubscribed means Removed',
        'unsubscribed', 'unsubscribed',
        'Added',
        'Removed'
      ],
      [
        'cleaned/unsubscribed means Removed',
        'cleaned', 'unsubscribed',
        'Added',
        'Removed'
      ],
      [
        'cleaned/unsubscribed means Removed',
        'unsubscribed', 'cleaned',
        'Added',
        'Removed'
      ],
      [
        'subscribed/subscribed means Added',
        'subscribed', 'subscribed',
        'Added',
        'Added'
      ],
      [
        'subscribed/subscribed means Added - should subscribe at CiviCRM',
        'subscribed', 'subscribed',
        'Removed',
        'Added'
      ],
      [
        'cleaned/unsubscribed means Removed - should stay removed',
        'unsubscribed', 'cleaned',
        'Removed',
        'Removed'
      ],
      // The following fail under issue #9
      [
        'unsubscribed/subscribed means Added',
        'unsubscribed', 'subscribed',
        'Added',
        'Added'
      ],
      [
        'subscribed/unsubscribed means Added',
        'subscribed', 'unsubscribed',
        'Added',
        'Added'
      ],
      [
        'subscribed/cleaned means Added',
        'subscribed', 'cleaned',
        'Added',
        'Added'
      ],
      // These tests check when CiviCRM was not subscribed.
      // They pass pre issue9
      // #10
      [
        'unsubscribed/unsubscribed means Added',
        'unsubscribed', 'unsubscribed',
        'Removed',
        'Removed'
      ],
      // #11
      [
        'unsubscribed/subscribed means Added',
        'unsubscribed', 'subscribed',
        'Removed',
        'Added'
      ],
      // #12
      [
        'subscribed/unsubscribed means Added',
        'subscribed', 'unsubscribed',
        'Removed',
        'Added'
      ],
      // #13
      [
        'subscribed/cleaned means Added',
        'subscribed', 'cleaned',
        'Removed',
        'Added'
      ],
    ];
  }
  /**
   * Provides test cases for testReconcileSubscriptionGroup
   *
   */
  public function reconcileSubscriptionGroupDataProvider() {
    $today = date('Y-m-d') . 'T00:00:00Z';
    return [
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
        'expected_mailchimp_updates' => ['email_address' => 'contact1@example.com', 'status' => 'subscribed'],
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

      [[
        'description' => "Strange case (should never happen) where contact apparently exists nowhere",
        'civicrm_status' => null,
        'civicrm_updated' => null,
        'mailchimp_status' => null,
        'mailchimp_updated' => null,
        'expected_group_status' => null,
        'expected_mailchimp_updates' => [],
      ]],

    ];
  }
  /**
   *
   */
  public function testReconcileQueueItemQueuesUpdate() {

    $_ = $this->createConfig2();
    $cache_entry = $_->cache_entry;
    $id = $cache_entry->id;
    $audience = $_->audience;

    // Call the thing we want to test:
    $cache_entry->civicrm_groups = $audience->getSubscriptionGroup() . ';Added;' . date('Y-m-d H:i:s');
    $audience->reconcileQueueItem($cache_entry, FALSE);

    // Now check that we have an update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $this->assertEquals(1, $update->count(), "Expect one update in update table.");
    $update->mailchimpsync_cache_id = $id;
    $this->assertEquals(1, $update->find(TRUE), "Expected to find an update record but did not.");
    $this->assertEquals(json_encode(['email_address' => 'contact1@example.com', 'status' => 'subscribed']), $update->data);
    // Reload cache entry
    $cache_entry = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_entry->id = $id;
    $cache_entry->find(TRUE);
    $this->assertEquals('live', $cache_entry->sync_status);

  }
  /**
   * Pretty much duplicate of testReconcileQueueItemQueuesUpdate but at higher level.
   */
  public function testReconcileQueueProcessQueuesUpdate() {

    $_ = $this->createConfig2();
    $cache_entry = $_->cache_entry;
    $id = $cache_entry->id;
    $audience = $_->audience;

    // Call the thing we want to test.
    // We give it 60s to complete. It should take milliseconds but hey.
    CRM_Mailchimpsync_BAO_MailchimpsyncCache::updateCiviCRMGroups(['list_id' => 'list_1']);
    $audience->reconcileQueueProcess(60, FALSE, FALSE);

    // Now check that we have an update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $this->assertEquals(1, $update->count(), "Expect one update in update table.");
    $update->mailchimpsync_cache_id = $id;
    $this->assertEquals(1, $update->find(TRUE), "Expected to find an update record but did not.");
    $this->assertEquals(json_encode(['email_address' => 'contact1@example.com', 'status' => 'subscribed']), $update->data);
    // Reload cache entry
    $cache_entry = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_entry->id = $id;
    $cache_entry->find(TRUE);
    $this->assertEquals('live', $cache_entry->sync_status);

  }
  /**
   */
  public function testBatchSubmission() {

    // Setup
    $_ = $this->createConfig2();
    $audience = $_->audience;
    // Add a 2nd contact that's already known to both systems.
    $contact_2 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test2', 'email' => 'contact2@example.com'])['id'];
    // Create cache record manually for our fixture.
    $cache_entry = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_entry->civicrm_contact_id = $contact_2;
    $cache_entry->sync_status = 'todo';
    $cache_entry->mailchimp_list_id = 'list_1';
    $cache_entry->mailchimp_email = 'contact2@example.com';
    $cache_entry->mailchimp_member_id = md5('contact2@example.com');
    $cache_entry->mailchimp_status = 'unsubscribed';
    $cache_entry->mailchimp_updated = '2000-01-01'; // old
    $cache_entry->subscribeInCiviCRM($audience);
    $cache_entry->save();

    // Set up updates (this is tested in other tests)
    CRM_Mailchimpsync_BAO_MailchimpsyncCache::updateCiviCRMGroups(['list_id' => 'list_1']);
    $audience->reconcileQueueProcess(FALSE, FALSE, FALSE);

    // Call the thing we want to test.
    $request_count = $audience->submitBatch();

    // We expect one request.
    $this->assertEquals(2, $request_count, "Expected two requests");

    // We expect there to be one batch now.
    $dao = new CRM_Mailchimpsync_DAO_MailchimpsyncBatch();
    $this->assertEquals(1, $dao->count(), "Expected one batch.");
    $dao->fetch();
    $batch_id = $dao->id;

    // We expect the update record to contain the ID of the batch.
    $update_dao = new CRM_Mailchimpsync_DAO_MailchimpsyncUpdate();
    $update_dao->mailchimpsync_batch_id = $batch_id;
    $this->assertEquals(2, $update_dao->find(), "Expected the update rows to be linked to the batch.");

    // We need the IDs from the update table.
    $update_ids = array_keys($update_dao->fetchMap('id', 'id'));

    // Check that the batch is correct.
    $api = $audience->getMailchimpApi();

    $got = $api->batches;
    $this->assertInternalType('string', $got['batch_0']['operations'][0]['body'] ?? NULL);
    $this->assertInternalType('string', $got['batch_0']['operations'][1]['body'] ?? NULL);
    // Decode the json because we can't guarantee the order it gets serialised in.
    $got['batch_0']['operations'][0]['body'] = json_decode($got['batch_0']['operations'][0]['body'], TRUE);
    $got['batch_0']['operations'][1]['body'] = json_decode($got['batch_0']['operations'][1]['body'], TRUE);

    $this->assertEquals([
      'batch_0' => [
        'operations' => [
          [
            'method' => 'POST',
            //'path' => '/lists/list_1/members/893149900bedab9c2dab6e8bbfebeea7',
            'path' => '/lists/list_1/members',
            'operation_id' => 'mailchimpsync_' . $update_ids[0],
            'body' => [
              'email_address' => 'contact1@example.com',
              'status' => 'subscribed',
            ]
          ],
          [
            'method' => 'PUT',
            'path' => '/lists/list_1/members/fc749d08d4b46319f7584a3483e7f5f2',
            'operation_id' => 'mailchimpsync_' . $update_ids[1],
            'body' => [
              'email_address' => 'contact2@example.com',
              'status' => 'subscribed',
            ]
          ]
        ]
      ]
    ], $got, "Failed checking batches were created as expected.");
  }
  /**
   * @expectedException CRM_Mailchimpsync_BatchWebhookNotRelevantException
   */
  public function testBatchWebhookProcessDoesNotProcessUnknownBatch() {
    $wh = new CRM_Mailchimpsync_Page_BatchWebhook();
    $wh->processWebhook([
      'type' => 'batch_operation_completed',
      'data' => [ 'id' => '123456789a' ],
    ]);
  }
  /**
   */
  public function testBatchWebhookCanProcessSuccessInSingleFile() {
    $various = $this->batchWebhookSetup();
    $cache = $various->cache_entry;
    $audience = $various->audience;
    $api = $audience->getMailchimpApi();

    // Now mock the responses
    $api->setMockMailchimpBatchResults('https://example.com/batch-1-results', [
      'file_uno.json' => [
      'data' => [
        'operation_id' => 'mailchimpsync_' . $various->update_id,
        'status_code' => 200,
        'response' => json_encode([
          'email_address' => 'contact1@example.com',
          'status' => 'subscribed',
          'id' => $api->getMailchimpMemberIdFromEmail('contact1@example.com'),
          'last_changed' => date('YmdHis'),
        ]),
      ]]
    ]);

    // Call the thing we want to test.
    $wh = new CRM_Mailchimpsync_Page_BatchWebhook();
    $wh->processWebhook([
      'type' => 'batch_operation_completed',
      'data' => [ 'id' => '123456789a' ],
    ]);

    // Load the batch, make sure it's been updated.
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_batch_id = '123456789a';
    $this->assertEquals(1, $batch->find(1));
    $this->assertEquals('finished', $batch->status);
    $this->assertEquals(1, $batch->finished_operations);
    $this->assertEquals(0, $batch->errored_operations);
    $this->assertEquals(1, $batch->total_operations);

    // Load the update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->id = $various->update_id;
    $this->assertEquals(1, $update->find(1));
    $this->assertEquals(1, $update->completed);
    $this->assertEquals(NULL, $update->error_response);

    // Load the cache item.
    $cache_id = $cache->id;
    $cache = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache->id = $cache_id;
    $this->assertEquals(1, $cache->find(1));
    $this->assertEquals('ok', $cache->sync_status);
  }
  /**
   * For some reason Mailchimp's batch responses sometimes come in multiple tarred files.
   */
  public function testBatchWebhookCanProcessSuccessInMultipleFiles() {
    $various = $this->batchWebhookSetup();
    $cache = $various->cache_entry;
    $audience = $various->audience;
    $api = $audience->getMailchimpApi();
    // Create second contact
    $contact_2 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test2', 'email' => 'contact2@example.com'])['id'];
    // Create second cache item.
    $cache_entry_2 = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_entry_2->civicrm_contact_id = $contact_2;
    $cache_entry_2->mailchimp_list_id = 'list_1';
    $cache_entry_2->mailchimp_status = 'subscribed';
    $cache_entry_2->mailchimp_updated = $this->a_week_ago;
    $cache_entry_2->civicrm_status = 'Removed';
    $cache_entry_2->civicrm_updated = $this->yesterday;
    $cache_entry_2->sync_status = 'live';
    $cache_entry_2->save();
    $various->cache_entry_2_id=$cache_entry_2->id;
    // Create second update
    $update2 = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update2->mailchimpsync_batch_id = $various->batch_id;
    $update2->mailchimpsync_cache_id = $cache_entry_2->id;
    $update2->data = '{"status":"unsubscribed", "email_address":"contact2@example.com"}';
    $update2->save();
    $various->update_id2 = $update2->id;
    // Adjust the response
    $api->setMockMailchimpBatchStatus('123456789a', [
      'status' => 'finished',
      'response_body_url' => 'https://example.com/batch-1-results',
      'completed_at' => date('Y-m-d H:i:s'),
      'submitted_at' => $this->a_week_ago,
      'finished_operations' => 2,
      'errored_operations' => 0,
      'total_operations' => 2,
    ]);


    $api->setMockMailchimpBatchResults('https://example.com/batch-1-results', [
      'file_uno.json' => [
        'data' => [
          'operation_id' => 'mailchimpsync_' . $various->update_id,
          'status_code' => 200,
          'response' => json_encode([
            'email_address' => 'contact1@example.com',
            'status' => 'subscribed',
            'id' => $api->getMailchimpMemberIdFromEmail('contact1@example.com'),
            'last_changed' => date('YmdHis'),
          ]),
        ],
      ],
      'file_duo.json' => [
        'data' => [
          'operation_id' => 'mailchimpsync_' . $various->update_id2,
          'status_code' => 200,
          'response' => json_encode([
            'email_address' => 'contact1@example.com',
            'status' => 'subscribed',
            'id' => $api->getMailchimpMemberIdFromEmail('contact1@example.com'),
            'last_changed' => date('YmdHis'),
          ]),
        ]
      ]
    ]);

    // Call the thing we want to test.
    $wh = new CRM_Mailchimpsync_Page_BatchWebhook();
    $wh->processWebhook([
      'type' => 'batch_operation_completed',
      'data' => [ 'id' => '123456789a' ],
    ]);

    // Load the batch, make sure it's been updated.
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_batch_id = '123456789a';
    $this->assertEquals(1, $batch->find(1));
    $this->assertEquals('finished', $batch->status);
    $this->assertEquals(2, $batch->finished_operations);
    $this->assertEquals(0, $batch->errored_operations);
    $this->assertEquals(2, $batch->total_operations);

    // Load the updates
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->id = $various->update_id;
    $this->assertEquals(1, $update->find(1));
    $this->assertEquals(1, $update->completed);
    $this->assertEquals(NULL, $update->error_response);

    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->id = $various->update_id2;
    $this->assertEquals(1, $update->find(1));
    $this->assertEquals(1, $update->completed);
    $this->assertEquals(NULL, $update->error_response);

    // Load the cache items.
    $cache_id = $cache->id;
    $cache = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache->id = $cache_id;
    $this->assertEquals(1, $cache->find(1));
    $this->assertEquals('ok', $cache->sync_status);

    $cache = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache->id = $various->cache_entry_2_id;
    $this->assertEquals(1, $cache->find(1));
    $this->assertEquals('ok', $cache->sync_status);

    //$this->dumpTables();
  }
  /**
   * It's feasible that a cache entry has two updates pending.
   *
   * In this case we don't want to set the cache's sync_status to 'ok' until
   * the 2nd one completes.
   */
  public function testBatchWebhookCanProcessSuccessWhen2UpdatesPending() {
    $various = $this->batchWebhookSetup();
    $cache = $various->cache_entry;
    $audience = $various->audience;
    $api = $audience->getMailchimpApi();

    // Create 2nd batch
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_batch_id = 'aabbccddee';
    $batch->mailchimp_list_id = 'list_1';
    $batch->save();
    $various->batch_id_2 = $batch->id;

    // Create second update on same cache record.
    $update2 = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update2->mailchimpsync_batch_id = $various->batch_id_2;
    $update2->mailchimpsync_cache_id = $various->cache_entry->id;
    $update2->data = '{"status":"unsubscribed", "email_address":"contact1@example.com"}';
    $update2->save();
    $various->update_id2 = $update2->id;

    $api->setMockMailchimpBatchResults('https://example.com/batch-1-results', [
      'file_uno.json' => [
        'data' => [
          'operation_id' => 'mailchimpsync_' . $various->update_id,
          'status_code' => 200,
          'response' => json_encode([
            'email_address' => 'contact1@example.com',
            'status' => 'subscribed',
            'id' => $api->getMailchimpMemberIdFromEmail('contact1@example.com'),
            'last_changed' => date('YmdHis'),
          ]),
        ],
      ],
    ]);

    // Call the thing we want to test.
    $wh = new CRM_Mailchimpsync_Page_BatchWebhook();
    $wh->processWebhook([
      'type' => 'batch_operation_completed',
      'data' => [ 'id' => '123456789a' ],
    ]);

    // Load the batch, make sure it's been updated.
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_batch_id = '123456789a';
    $this->assertEquals(1, $batch->find(1));
    $this->assertEquals('finished', $batch->status);
    $this->assertEquals(1, $batch->finished_operations);
    $this->assertEquals(0, $batch->errored_operations);
    $this->assertEquals(1, $batch->total_operations);

    // Load the 2nd batch, make sure it's not been updated.
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_batch_id = 'aabbccddee';
    $this->assertEquals(1, $batch->find(1));
    $this->assertEquals(NULL, $batch->status);
    $this->assertEquals(0, $batch->finished_operations);

    // Load the updates
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->id = $various->update_id;
    $this->assertEquals(1, $update->find(1));
    $this->assertEquals(1, $update->completed);
    $this->assertEquals(NULL, $update->error_response);

    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->id = $various->update_id2;
    $this->assertEquals(1, $update->find(1));
    $this->assertEquals(0, $update->completed);
    $this->assertEquals(NULL, $update->error_response);

    // Load the cache items.
    $cache = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache->id = $various->cache_entry->id;
    $this->assertEquals(1, $cache->find(1));
    $this->assertEquals('live', $cache->sync_status);

    //$this->dumpTables();
  }
  /**
   * If we try to subscribe someone and get an error about compliance,
   * we can retry to set them 'pending' which causes Mailchimp itself to send
   * them an email asking if they want to join the list.
   *
   */
  public function testBatchWebhookHandlesComplianceFailures() {
    $various = $this->batchWebhookSetup();
    $cache = $various->cache_entry;

    //Load the update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->id = $various->update_id;
    $update->find(1);

    // Call the thing we want to test:
    $returned_error = [
        'title' => 'Member In Compliance State',
        'status' => 400, // we don't test this
        'detail' => '...', // we don't test this
        'type' => '...', // we don't test this
      ];
    $update->handleMailchimpUpdatesResponse([
      'status_code' => 400,
      'response' => $returned_error,
    ]);

    // Check the updates.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->mailchimpsync_cache_id = $various->cache_entry->id;
    $this->assertEquals(2, $update->find());
    while ($update->fetch()) {
      if ($update->id == $various->update_id) {
        $this->assertEquals(1, $update->completed);
        $this->assertEquals($returned_error, json_decode($update->error_response, TRUE));
      }
      else {
        $this->assertEquals(0, $update->completed);
        $data = json_decode($update->data, TRUE);
        $this->assertEquals('pending', $data['status'] ?? '');
      }
    }

    // Load the cache item.
    $cache = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache->id = $various->cache_entry->id;
    $this->assertEquals(1, $cache->find(1));
    $this->assertEquals('live', $cache->sync_status);

    //$this->dumpTables();
  }
  /**
   * If we try to subscribe someone and get an error not about compliance,
   * we have to flag it as failed.
   *
   */
  public function testBatchWebhookHandlesFailure() {
    $various = $this->batchWebhookSetup();
    $cache = $various->cache_entry;

    //Load the update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->id = $various->update_id;
    $update->find(1);

    // Call the thing we want to test:
    $returned_error = [
        'title' => 'No way, this person hates you.',
        // Mailchimp has some other bits here
      ];
    $update->handleMailchimpUpdatesResponse([
      'status_code' => 400,
      'response' => $returned_error,
    ]);

    // Check the updates.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->id = $various->update_id;
    $this->assertEquals(1, $update->find(1));
    $this->assertEquals(1, $update->completed);
    $this->assertEquals($returned_error, json_decode($update->error_response, TRUE));

    // Load the cache item.
    $cache = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache->id = $various->cache_entry->id;
    $this->assertEquals(1, $cache->find(1));
    $this->assertEquals('fail', $cache->sync_status);

    //$this->dumpTables();
  }
  /**
   */
  public function testWebhookHandlesSubscribe() {
    // Set up
    $audience = $this->createConfigFixture1AndGetAudience(TRUE);
    // Mock get for this contact.
    $api = $audience->getMailchimpApi();
    $this->setMockSubscriberData1($api);

    $wh = new CRM_Mailchimpsync_Page_Webhook();
    $wh->processSubscribe([
      'type' => 'subscribe',
      'data' => [
        'list_id' => 'list_1',
        'email' => 'wilma@example.com',
      ]
    ]);

    // We should now have wilma in the database.
    $c = civicrm_api3('contact', 'getsingle', ['email' => 'wilma@example.com']);
    $this->assertEquals('Wilma', $c['first_name']);
    $this->assertEquals('Flintstone', $c['last_name']);
    $this->assertEquals('Flintstone', $c['last_name']);
    $this->assertContactIsInGroup($c['id'], $audience->getSubscriptionGroup());

    // Repeat webhook - nothing should change.
    $wh->processSubscribe([
      'type' => 'subscribe',
      'data' => [
        'list_id' => 'list_1',
        'email' => 'wilma@example.com',
      ]
    ]);

    // We should now have wilma in the database.
    // If a 2nd contact was added, the next line would throw exception.
    $c = civicrm_api3('contact', 'getsingle', ['email' => 'wilma@example.com']);
    $this->assertContactIsInGroup($c['id'], $audience->getSubscriptionGroup());

  }
  /**
   * Test profile updates are handled.
   *
   * Names: a profile update should update CiviCRM's name, if different and as
   * long as the new name is not blank.
   */
  public function testWebhookHandlesProfileUpdate() {
    // Set up
    $various = $this->createConfig2();
    $audience = $various->audience;
    $cache_entry = $various->cache_entry;
    $api = $audience->getMailchimpApi();

    // Mock get for this contact.
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'test1', 'lname' => '', 'email' => 'contact1@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
        ],
      ],
    ]);


    // Update cache to be in sync with mailchimp.
    $cache_entry->mailchimp_status = 'subscribed';
    $cache_entry->mailchimp_updated = date('YmdHis');
    $cache_entry->mailchimp_id = $api->getMailchimpMemberIdFromEmail($cache_entry->mailchimp_email);
    $cache_entry->save();

    // Now change data.
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'test1', 'lname' => 'Changed', 'email' => 'contact1@example.com', 'status' => 'subscribed', 'last_changed' => $this->a_week_ago ],
        ],
      ],
    ]);

    // Call Profileup
    $wh = new CRM_Mailchimpsync_Page_Webhook();
    $wh->processProfile([
      'type' => 'subscribe',
      'data' => [
        'list_id' => 'list_1',
        'email' => 'contact1@example.com',
        'merges' => [
          'FNAME' => '',
          'LNAME' => 'Changed',
        ]
      ]
    ]);

    // We should now have wilma in the database.
    $c = civicrm_api3('contact', 'getsingle', ['id' => $cache_entry->civicrm_contact_id]);
    $this->assertContactIsInGroup($c['id'], $audience->getSubscriptionGroup());
    $this->assertEquals('test1', $c['first_name']);
    $this->assertEquals('Changed', $c['last_name']);

  }
  /**
   */
  public function testWebhookHandlesUnsubscribe() {
    // Set up
    $various = $this->createConfig2();
    $audience = $various->audience;
    $cache_entry = $various->cache_entry;
    $api = $audience->getMailchimpApi();

    // Update cache to be in sync with mailchimp.
    $cache_entry->mailchimp_status = 'subscribed';
    $cache_entry->mailchimp_updated = date('YmdHis');
    $cache_entry->mailchimp_email = 'contact1@example.com';
    $cache_entry->mailchimp_member_id = $api->getMailchimpMemberIdFromEmail($cache_entry->mailchimp_email);
    $cache_entry->sync_status = 'ok';
    $cache_entry->save();

    // Mock get for this contact.
    // Nb. we spoof a date slightly in the future because this test creates the
    // civi group contact record then immediately fires the mailchimp, but
    // we're testing the case when mailchimp comes 2nd.
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'test1', 'lname' => '', 'email' => 'contact1@example.com', 'status' => 'unsubscribed', 'last_changed' => date('YmdHis', time() + 2) ],
        ],
      ],
    ]);

    // Call the webhook
    $wh = new CRM_Mailchimpsync_Page_Webhook();
    $wh->processUnsubscribe([
      'type'    => 'unsubscribe',
      'data' => [
        'list_id' => 'list_1',
        'email'   => 'contact1@example.com',
      ]
    ]);

    $c = civicrm_api3('contact', 'getsingle', ['id' => $cache_entry->civicrm_contact_id]);
    $this->assertContactIsNotInGroup($c['id'], $audience->getSubscriptionGroup());
  }
  /**
   */
  public function testWebhookHandlesCleaned() {
    // Set up
    $various = $this->createConfig2();
    $audience = $various->audience;
    $cache_entry = $various->cache_entry;
    $api = $audience->getMailchimpApi();

    // Update cache to be in sync with mailchimp.
    $cache_entry->mailchimp_status = 'subscribed';
    $cache_entry->mailchimp_updated = date('YmdHis');
    $cache_entry->mailchimp_email = 'contact1@example.com';
    $cache_entry->mailchimp_member_id = $api->getMailchimpMemberIdFromEmail($cache_entry->mailchimp_email);
    $cache_entry->sync_status = 'ok';
    $cache_entry->save();

    // Mock get for this contact.
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'test1', 'lname' => '', 'email' => 'contact1@example.com', 'status' => 'unsubscribed', 'last_changed' => date('YmdHis', time() + 2) ],
        ],
      ],
    ]);

    // Call the webhook
    $wh = new CRM_Mailchimpsync_Page_Webhook();
    $wh->processCleaned([
      'type'    => 'cleaned',
      'data' => [
        'list_id' => 'list_1',
        'email'   => 'contact1@example.com',
      ]
    ]);

    $c = civicrm_api3('contact', 'getsingle', ['id' => $cache_entry->civicrm_contact_id]);
    $this->assertContactIsNotInGroup($c['id'], $audience->getSubscriptionGroup());

    // Check the email was put on hold.
    $email = civicrm_api3('Email', 'getsingle', ['email' => 'contact1@example.com']);
    $this->assertEquals(1, $email['on_hold'], "Email is not on hold, but should be");
  }
  /**
   */
  public function testWebhookHandlesEmailUpdate() {
    // Set up
    $various = $this->createConfig2();
    $audience = $various->audience;
    $cache_entry = $various->cache_entry;
    $api = $audience->getMailchimpApi();

    // Update cache to be in sync with mailchimp.
    $cache_entry->mailchimp_status = 'subscribed';
    $cache_entry->mailchimp_updated = date('YmdHis');
    $cache_entry->mailchimp_email = 'contact1@example.com';
    $cache_entry->mailchimp_member_id = $api->getMailchimpMemberIdFromEmail($cache_entry->mailchimp_email);
    $cache_entry->sync_status = 'ok';
    $cache_entry->save();

    // Mock get for this contact.
    $api->setMockMailchimpData([
      'list_1' => [
        'members' => [
          [ 'fname' => 'test1', 'lname' => '', 'email' => 'contact1@example.com', 'status' => 'unsubscribed', 'last_changed' => date('YmdHis', time() + 2) ],
        ],
      ],
    ]);

    // Call the webhook
    $wh = new CRM_Mailchimpsync_Page_Webhook();
    $wh->processUpemail([
      'type'    => 'upemail',
      'data' => [
        'list_id' => 'list_1',
        'old_email'   => 'contact1@example.com',
        'new_email'   => 'contact1@example.uk',
      ]
    ]);

    // Check the email was put on hold.
    $email = civicrm_api3('Email', 'getsingle', ['email' => 'contact1@example.uk', 'contact_id' => $cache_entry->civicrm_contact_id]);
    $this->assertEquals(1, $email['is_bulkmail'], "Email found but not bulkmail");
  }
  // Test helpers.
  public function dumpTables() {
    print "\nContacts: \n" . $this->dumpSql("SELECT id, first_name FROM civicrm_contact ORDER BY id") . "\n";
    print "Emails: \n" . $this->dumpSql("SELECT id, contact_id, email FROM civicrm_email ORDER BY contact_id") . "\n";
    print "Cache: \n" . $this->dumpSql("SELECT * FROM civicrm_mailchimpsync_cache", [], JSON_PRETTY_PRINT) . "\n";
    print "Updates: \n" . $this->dumpSql("SELECT * FROM civicrm_mailchimpsync_update") . "\n";
    print "Batches: \n" . $this->dumpSql("SELECT * FROM civicrm_mailchimpsync_batch") . "\n";
  }
  public function dumpSql($sql, $params=[], $pretty=FALSE) {
    $results = CRM_Core_DAO::executeQuery($sql, $params)->fetchAll();
    $output = '';
    foreach ($results as $row) {
      $output .= json_encode($row, $pretty) . "\n";
    }
    return "$output\n";
  }

  public function assertContactIsInGroup($contact_id, $group_id) {
    $r = civicrm_api3('Contact', 'get', ['contact_id' => $contact_id, 'group' => $group_id]);
    $this->assertEquals(1, $r['count'], "Expected that contact was in group, but isn't.");
  }
  public function assertContactIsNotInGroup($contact_id, $group_id) {
    $r = civicrm_api3('Contact', 'get', ['contact_id' => $contact_id, 'group' => $group_id]);
    $this->assertEquals(0, $r['count'], "Expected that contact was not in group, but is.");
  }
  /**
   * Get lots of stats info on an audience and return a hash.
   *
   * This is used in the isolation test.
   */
  public function getAudienceHash($audience) {
    $hash = md5(json_encode([$audience->getConfig(), $audience->getStatus(), $audience->getStats()]));
    return $hash;
  }
}
