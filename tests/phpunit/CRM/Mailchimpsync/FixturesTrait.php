<?php
trait CRM_Mailchimpsync_FixturesTrait {

  /**
   * Set up one list in one account linked to one group.
   *
   * @param bool $with_group - whether to create a subscription group for this.
   *
   * @return array of CRM_Mailchimpsync_Audience objects, indexed from 1
   */
  protected function createConfigFixture1AndGetAudience($with_group = FALSE) {
    return $this->createConfigFixtureAndGetAudience(1, $with_group)[1];
  }
  /**
   * Set up two lists in one account linked to one group.
   *
   * @param int $n number of lists to create
   * @param bool $with_group - whether to create a subscription group for this.
   *
   * @return array of CRM_Mailchimpsync_Audience objects, indexed from 1
   */
  protected function createConfigFixtureAndGetAudience($n=1, $with_group = FALSE) {

    $config = [
      'lists' => [],
      'accounts' => [
        'mock_account_1' => [
          'audiences' => [ ],
          'batchWebhookSecret' => 'MockBatchWebhookSecret',
        ]
      ]
    ];

    for($i=1; $i<=$n; $i++) {
      $config['accounts']['mock_account_1']['audiences']["list_$i"] = [];

      if ($with_group) {
        $group_id = civicrm_api3('Group', 'create', [
          'name'       => "test_list_$i",
          'title'      => "test_list_$i",
          'group_type' => "Mailing List",
        ])['id'];
      }
      else {
        $group_id = NULL;
      }

      $config['lists']["list_$i"] = [
          'apiKey'            => 'mock_account_1',
          'subscriptionGroup' => $group_id,
      ];
    }

    CRM_Mailchimpsync::setConfig($config);

    $audiences = [];
    for($i=1; $i<=$n; $i++) {
      $audiences[$i] = CRM_Mailchimpsync_Audience::newFromListId("list_$i");
    }
    return $audiences;
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
  /**
   * DRY used in testBatchSubmission
   *
   * - Sets up fixture 1 with group
   * - create a test contact
   * - adds contact to test subscription group
   * - cache is left without mailchimp fields except list_id
   *
   * @return StdClass with props:
   * - CRM_Mailchimpsync_Audience audience
   * - CRM_Mailchimpsync_BAO_MailchimpsyncCache cache_entry
   */
  protected function createConfig2() {
    // Check that mailchimp updates get added to the mailchimpsync_update table.
    $audience = $this->createConfigFixture1AndGetAudience(TRUE);
    // Create one test contact.
    $contact_1 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test1', 'last_name' => 'test1last', 'email' => 'contact1@example.com'])['id'];
    // Create cache record manually for our fixture.
    $cache_entry = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache_entry->civicrm_contact_id = $contact_1;
    $cache_entry->mailchimp_list_id = 'list_1';
    $cache_entry->subscribeInCiviCRM($audience);
    $cache_entry->save();
    return (object) [
      'audience' => $audience,
      'cache_entry' => $cache_entry,
    ];
  }
  /**
   * DRY code
   *
   * - Creates a mock batch with one update, subscribing contact1
   * - Sets contact1's cache status to live.
   * - Mocks a successful batch webhook response saying the updates all went OK.
   *
   * @return StdClass with props:
   * - audience
   * - cache_entry
   * - batch_id
   * - update_id
   */
  protected function batchWebhookSetup() {
    // We need fixture for a webhook.
    $various = $this->createConfig2();
    $audience = $various->audience;

    // Create mock batch.
    $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
    $batch->mailchimp_batch_id = '123456789a';
    $batch->mailchimp_list_id = 'list_1';
    $batch->status = 'pending';
    $batch->save();
    $various->batch_id = $batch->id;

    // Create mock update.
    $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
    $update->mailchimpsync_batch_id = $batch->id;
    $update->mailchimpsync_cache_id = $various->cache_entry->id;
    $update->data = '{"status":"subscribed", "email_address":"contact1@example.com"}';
    $update->save();
    $various->update_id = $update->id;

    // The cache item would be set 'live' by now.
    $various->cache_entry->sync_status = 'live';
    $various->cache_entry->save();

    // Now mock the responses
    $api = $audience->getMailchimpApi();
    $api->setMockMailchimpBatchStatus('123456789a', [
      'status' => 'finished',
      'response_body_url' => 'https://example.com/batch-1-results',
      'completed_at' => date('Y-m-d H:i:s'),
      'submitted_at' => date('Y-m-d H:i:s'),
      'finished_operations' => 1,
      'errored_operations' => 0,
      'total_operations' => 1,
    ]);

    return $various;
  }
  public function setMockSubscriberData1($api) {
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
  }
}
