<?php
trait CRM_Mailchimpsync_FixturesTrait {

  /**
   * Set up simple config, return an audience for it.
   *
   * @param return CRM_Mailchimpsync_Audience
   */
  protected function createConfigFixture1AndGetAudience($with_group = FALSE) {
    // Clear out locks.
    Civi::settings()->set("mailchimpsync_audience_status_list_1", NULL);

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
      ],
      'accounts' => [
        'mock_account_1' => [
          'audiences' => [
            'list_1' => [ ]
          ]
        ]
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
  /**
   * DRY used in testBatchSubmission
   *
   * - Sets up fixture 1 with group
   * - create a test contact
   * - adds contact to test subscription group
   *
   * @return StdClass with props:
   * - CRM_Mailchimpsync_Audience audience
   * - CRM_Mailchimpsync_BAO_MailchimpsyncCache cache_entry
   */
  protected function createConfig2() {
    // Check that mailchimp updates get added to the mailchimpsync_update table.
    $audience = $this->createConfigFixture1AndGetAudience(TRUE);
    // Create one test contact.
    $contact_1 = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'test1', 'email' => 'contact1@example.com'])['id'];
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
}
