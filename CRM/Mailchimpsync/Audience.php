<?php
/**
 * Class to represent a Mailchimp Audience(list) that is synced with CiviCRM.
 *
 * This handles the config for this audience, too.
 *
 */
class CRM_Mailchimpsync_Audience
{
  /** @var string */
  protected $mailchimp_list_id;

  /** @var array */
  protected $config;

  /** Cached mailchimpsync_audience_status_* value */
  protected $status_cache;

  protected function __construct(string $list_id) {
    $this->mailchimp_list_id = $list_id;

    $this->config = CRM_Mailchimpsync::getConfig()['lists'][$list_id]
      ?? [
        'subscriptionGroup' => 0,
        'api_key' => NULL,
      ];
  }

  public static function newFromListId($list_id) {
    $obj = new static($list_id);
    return $obj;
  }
  /**
   * Instantiate object given CiviCRM group ID.
   *
   * @throws InvalidArgumentException if group ID not found in config.
   *
   * @return CRM_Mailchimpsync_Audience
   */
  public static function newFromGroupId($group_id) {
    $config = CRM_Mailchimpsync::getConfig();
    foreach ($config['lists'] ?? [] as $list_id => $list_config) {
      if ($group_id == $list_config['subscriptionGroup']) {
        return static::newFromListId($list_id);
      }
    }
    throw new \InvalidArgumentException("Unknown group ID `$group_id`");
  }
  /**
   * Setter for List ID.
   *
   * @param string $list_id
   *
   * @return CRM_Mailchimpsync_Audience
   */
  public function setListId($list_id) {
    $this->mailchimp_list_id = $list_id;
    return $this;
  }
  /**
   * Setter for CiviCRM Group.
   *
   * @param int $group_id
   *
   * @return CRM_Mailchimpsync_Audience
   */
  public function setSubscriptionGroup($group_id) {
    $this->config['subscriptionGroup'] = $group_id;
    return $this;
  }
  /**
   * Getter for List ID.
   *
   * @return string
   */
  public function getListId() {
    return $this->mailchimp_list_id;
  }
  /**
   * Getter for CiviCRM Group.
   *
   * @return int
   */
  public function getSubscriptionGroup() {
    if (empty($this->config['subscriptionGroup'])) {
      throw new \Exception("No subscription group configured for list $this->mailchimp_list_id");
    }
    return (int) $this->config['subscriptionGroup'];
  }
  /**
   * Return the config for this list from mailchimpsync_config setting.
   * @return array
   */
  public function getConfig() {
    return $this->config;
  }
  /**
   * Update/set the config for this list from mailchimpsync_config setting.
   * @return CRM_Mailchimpsync_Audience $this
   */
  public function setConfig($local_config) {

    // Ensure we have defaults.
    $local_config += [
        'subscriptionGroup' => 0,
        'api_key' => NULL,
    ];
    $global_config = CRM_Mailchimpsync::getConfig();
    $global_config['lists'][$this->mailchimp_list_id] = $local_config;
    CRM_Mailchimpsync::setConfig($global_config);

    // Update our cache of the config.
    $this->config = $local_config;

    return $this;
  }
  /**
   * This function deals with the fetch and reconciliation phases of the sync.
   *
   * Things have to be completed in order, but we have to break it up into jobs
   * that can run within a reasonable time limit. If called by a CLI script
   * it will just all run through one after another, but otherwise it can be re-run
   * and pick up where it left off.
   *
   * @param array $params with optional keys:
   * - time_limit: stop after this number of seconds. Default is 1 week (assumed to basically mean no time limit).
   * - since: a fixed since date that strtotime understands. Or pass an empty string meaning no limit, fetch all.
   *          if not present in parame, defaults to date last sync date.
   * - stop_on: name the ready state you want to stop processing on, e.g. readyToSubmitUpdates
   */
  public function fetchAndReconcile($params) {

    $params += ['time_limit' => 604800];
    $stop_time = time() + $params['time_limit'];

    // Calculate 'since' option.
    // Note: this is only used when the fetch begins. At that point it is
    // stored with the status of the job so that subsequent calls can continue
    // with the same setting.
    $sync_since = [];
    if (isset($params['since'])) {
      if ($params['since']) {
        $_ = strtotime($params['since']);
        if ($_ !== FALSE) {
          $sync_since['since'] = date(DATE_ISO8601, $_);
        }
      }
    }
    else {
      // Try to default to last time sync ran.
      $status = $this->getStatus();
      if (!empty($status['lastSyncTime'])) {
        $sync_since['since'] = $status['lastSyncTime'];
      }
    }

    $remaining_time = $stop_time - time();

    while ($remaining_time > 0) {
      $status = $this->getStatus();
      $current_state = $status['locks']['fetchAndReconcile'] ?? NULL;

      if (($params['stop_on'] ?? 'no stopping') === $current_state) {
        $this->log("Stopping as requested at step $params[stop_on]");
        return; // exit the loop completely.
      }

      switch ($current_state) {

      // Busy states - this is to prevent a 2nd cron job firing over the top of this one.
      case 'busy':
        $this->log("Called but locks say process already busy. Will not do anything.");
        return; // exit.

      case NULL:
      case 'readyToFetch':
        $this->mergeMailchimpData(['max_time' => $remaining_time] + $sync_since);
        break;

      case 'readyToFixContactIds':
        // Assume these two are quick enough to run together as one; they're all SQL driven.
        $this->updateLock([
          'for'    => 'fetchAndReconcile',
          'to'     => 'busy',
          'andLog' => 'Beginning to remove invalid contact IDs and populate missing ones from email matches.'
        ]);
        $this->removeInvalidContactIds();
        $this->populateMissingContactIds();
        $this->updateLock([
          'for'    => 'fetchAndReconcile',
          'is'     => 'readyToCreateNewContactsFromMailchimp',
        ]);
        break;

      case 'readyToCreateNewContactsFromMailchimp':
        $total = $this->createNewContactsFromMailchimp($remaining_time);
        break;

      case 'readyToAddCiviOnly':
        $total = $this->addCiviOnly();
        break;

      case 'readyToCopyCiviGroupStatus':
        $this->copyCiviCRMSubscriptionGroupStatus($status['fetch']['since'] ?? '');
        break;

      case 'readyToReconcileQueue':
        $stats = $this->reconcileQueueProcess($remaining_time);
        break;

      case 'readyToSubmitUpdates':
        $stats = $this->submitUpdatesBatches($remaining_time);
        if ($stats['is_complete']) {
          $this->log('fetchAndReconcile process is complete.');
        }
        return;
        // break 2;

      default:
        throw new Exception("Invalid lock: {$status['locks']['fetchAndReconcile']}");
      }

      // Update remaining time.
      $remaining_time = $stop_time - time();
    }

    if ($remaining_time <= 0) {
      $this->log('Stopping processing as out of time.');
    }
  }
  // The following methods deal with the 'fetch' phase
  /**
   * Merge subscriber data form Mailchimp into our table.
   *
   * @param array $params with keys:
   * - since    Only load things changed since this date (optional)
   *            Nb. this is only used when doing the first chunk of the fetch
   *            We then store it in our status and subsequent calls use that.
   *
   * - max_time Don't continue to fetch another batch if it has already taken
   *            longer than this number of seconds.
   *
   */
  public function mergeMailchimpData(array $params=[]) {

    $this->updateLock([
      'for'    => 'fetchAndReconcile',
      'is'     => 'busy',
      'andLog' => 'mergeMailchimpData: beginning to fetch data from mailchimp.'
    ]);

    try {
      $status = $this->getStatus();

      // 1 week max excution time, expected to be equivalent to no max time.
      $params += ['max_time' => 60*60*24*7];
      $stop_time = time() + $params['max_time'];

      $api = $this->getMailchimpApi();

      $query = [
        'count'  => $api->max_members_to_fetch,
        'offset' => ($status['fetch']['offset'] ?? 0),
      ];

      if ($query['offset'] === 0) {
        // This is the start of a new sync process. Update the latest sync time.
        // We want that time to be as early as possible since it's used to fetch
        // changes since this date in the next sync.
        //
        // Also, empty the previous log.
        // Also, store a 'since' date that will persist through multiple calls
        // of fetchAndReconcile
        if (!empty($params['since'])) {
          // Use a since time. Nb. we allow 2 hours overlap to be safe.
          $_ = strtotime($params['since']) - 2*60*60;
          if ($_) {
            $query['since_last_changed'] = date(DATE_ISO8601, $_);
          }
        }

        $this->updateAudienceStatusSetting(function(&$c) use ($query) {
          $c['lastSyncTime'] = date(DATE_ISO8601);
          $c['log'] = [];
          $c['fetch']['since'] = $query['since_last_changed'] ?? '';
        });
      }
      else {
        // This is a subsequent call. Load initial 'since' config.
        if ($status['fetch']['since'] ?? '') {
          $query['since_last_changed'] = $status['fetch']['since'];
        }
      }

      do {
        $this->log("fetchMergeMailchimpData: fetching up to $api->max_members_to_fetch records from offset $query[offset] "
          . (($query['since_last_changed'] ?? '') ? ' since ' . $query['since_last_changed'] : ''));
        $response = $api->get("lists/$this->mailchimp_list_id/members", $query);

        // Insert it into our cache table.
        foreach ($response['members'] ?? [] as $member) {
          $this->mergeMailchimpMember($member);
        }

        // Prepare to load next page.
        $query['offset'] += $api->max_members_to_fetch;

        $more_to_fetch = $response['total_items'] - $query['offset'];

        if ($more_to_fetch > 0) {
          $this->updateAudienceStatusSetting(function(&$c) use ($query) {
            // Store current offset, total to do.
            $c['fetch']['offset'] = $query['offset'];
          });
        }

      } while ($more_to_fetch>0 && time() < $stop_time);
    }
    catch (Exception $e) {
      // Release lock (to allow fetch to try again) before rethrowing.
      $this->log('fetchMergeMailchimpData: ERROR: ' . $e->getMessage());
      $this->updateLock(['for' => 'fetchAndReconcile', 'is' => 'readyToFetch']);
      throw $e;
    }

    // Successful finish, we're now ready to reconcile.
    if ($more_to_fetch>0) {
      $this->updateLock([
        'for'    => 'fetchAndReconcile',
        'is'     => 'readyToFetch',
        'andLog' => "fetchMergeMailchimpData: stopping but $more_to_fetch more to fetch" ]);
    }
    else {
      // We've fetched everything.
      $this->updateLock([
        'for'     => 'fetchAndReconcile',
        'is'      => 'readyToFixContactIds',
        'andLog'  => "fetchMergeMailchimpData: completed ($response[total_items] fetched).",
        // Reset the offset for next time.
        'andAlso' => function(&$c) { $c['fetch']['offset'] = 0; }
      ]);
    }
  }
  /**
   * Copy data from mailchimp into our table.
   *
   * @param object $member
   *
   * @return CRM_Mailchimpsync_BAO_MailchimpsyncCache
   */
  public function mergeMailchimpMember($member) {
    // Find ID in table.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_member_id = $member['id'];
    $bao->mailchimp_list_id = $this->mailchimp_list_id;
    if (!$bao->find(1)) {
      // New person.
      $bao->mailchimp_email = $member['email_address'];
    }

    $bao->sync_status = 'todo';
    $bao->mailchimp_status = $member['status'];
    $bao->mailchimp_updated = $member['last_changed'];

    // Create JSON data from Mailchimp.
    $data = [
      'first_name' => $member['merge_fields']['FNAME'] ?? NULL,
      'last_name'  => $member['merge_fields']['LNAME'] ?? NULL,
      'interests'  => $member['interests'] ?? [],
    ];
    // Nb. while I prefer JSON for readability, PHP's serialize is a *lot*
    // faster, which will count on a big list.
    $bao->mailchimp_data = serialize($data);

    // Update
    $bao->save();

    return $bao;
  }

  /**
   * Remove invalid CiviCRM data.
   *
   * e.g. if a contact is deleted (including a merge).
   *
   * @return int Number of affected rows.
   */
  public function removeInvalidContactIds() {

    $sql = "
      UPDATE civicrm_mailchimpsync_cache mc
        LEFT JOIN civicrm_contact cc ON mc.civicrm_contact_id = cc.id AND cc.is_deleted = 0
         SET civicrm_contact_id = NULL, civicrm_data = NULL, sync_status = 'todo'
       WHERE mc.civicrm_contact_id IS NOT NULL
             AND cc.id IS NULL
             AND mc.mailchimp_list_id = %1;";

    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$this->mailchimp_list_id, 'String']]);

    $affected = $dao->affectedRows();
    $this->log("removeInvalidContactIds: Removed $affected stale CiviCRM Contact IDs");
    return $affected;
  }
  /**
   * Try various techniques for finding an appropriate CiviCRM Contact ID from
   * the email found at Mailchimp.
   *
   * @return array of stats.
   */
  public function populateMissingContactIds() {

    $stats = [
      'found_by_single_email'                           => 0,
      'used_first_undeleted_contact_in_group'           => 0,
      'used_first_undeleted_contact_with_group_history' => 0,
      'used_first_undeleted_contact'                    => 0,
      'remaining'                                       => 0,
    ];

    // Don't run expensive queries if we don't have to: see what's to do.
    $stats['remaining'] = (int) CRM_Core_DAO::executeQuery(
      'SELECT COUNT(*) FROM civicrm_mailchimpsync_cache WHERE mailchimp_list_id = %1 AND civicrm_contact_id IS NULL',
      [1 => [$this->mailchimp_list_id, 'String']]
    )->fetchValue();

    if ($stats['remaining'] == 0) {
      // No need!
      return $stats;
    }

    //
    // 1. If we find that the email is owned by a single non-deleted contact, use that.
    //
    $sql = "
      UPDATE civicrm_mailchimpsync_cache mc
        INNER JOIN (
          SELECT e.email, MIN(e.contact_id) contact_id
            FROM civicrm_email e
            INNER JOIN civicrm_contact c1 ON e.contact_id = c1.id AND NOT c1.is_deleted
          GROUP BY e.email
          HAVING COUNT(DISTINCT e.contact_id) = 1
        ) c ON c.email = mc.mailchimp_email
        SET mc.civicrm_contact_id = c.contact_id
        WHERE mc.civicrm_contact_id IS NULL
              AND c.contact_id IS NOT NULL
              AND mc.mailchimp_list_id = %1
      ";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$this->mailchimp_list_id, 'String']]);
    $stats['found_by_single_email'] = $dao->affectedRows();
    $stats['remaining'] -= $stats['found_by_single_email'];

    if ($stats['remaining'] == 0) {
      // All done.
      return $stats;
    }

    //
    // 2. Next, use the first (lowest contact ID) that owns the email that is Added to the group.
    //
    $civicrm_subscription_group_id = $this->getSubscriptionGroup();
    $sql = "
      UPDATE civicrm_mailchimpsync_cache mc
        INNER JOIN (
          SELECT e.email, MIN(e.contact_id) contact_id
            FROM civicrm_email e
            INNER JOIN civicrm_contact c1 ON e.contact_id = c1.id AND NOT c1.is_deleted
            INNER JOIN civicrm_group_contact g
                      ON e.contact_id = g.contact_id
                      AND g.group_id = $civicrm_subscription_group_id
                      AND g.status = 'Added'
          GROUP BY e.email
        ) c ON c.email = mc.mailchimp_email
        SET mc.civicrm_contact_id = c.contact_id
        WHERE mc.civicrm_contact_id IS NULL
              AND c.contact_id IS NOT NULL
              AND mc.mailchimp_list_id = %1
      ";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$this->mailchimp_list_id, 'String']]);
    $stats['used_first_undeleted_contact_in_group'] = $dao->affectedRows();
    $stats['remaining'] -= $stats['used_first_undeleted_contact_in_group'];

    if ($stats['remaining'] == 0) {
      // No need!
      return $stats;
    }

    //
    // 3. Next, use the first (lowest contact ID) that owns the email that has any group history.
    //
    $civicrm_subscription_group_id = $this->getSubscriptionGroup();
    $sql = "
      UPDATE civicrm_mailchimpsync_cache mc
        INNER JOIN (
          SELECT e.email, MIN(e.contact_id) contact_id
            FROM civicrm_email e
            INNER JOIN civicrm_contact c1 ON e.contact_id = c1.id AND NOT c1.is_deleted
            INNER JOIN civicrm_subscription_history h
                      ON e.contact_id = h.contact_id
                      AND h.group_id = $civicrm_subscription_group_id
          GROUP BY e.email
        ) c ON c.email = mc.mailchimp_email
        SET mc.civicrm_contact_id = c.contact_id
        WHERE mc.civicrm_contact_id IS NULL
              AND c.contact_id IS NOT NULL
              AND mc.mailchimp_list_id = %1
      ";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$this->mailchimp_list_id, 'String']]);
    $stats['used_first_undeleted_contact_with_group_history'] = $dao->affectedRows();
    $stats['remaining'] -= $stats['used_first_undeleted_contact_with_group_history'];

    if ($stats['remaining'] == 0) {
      // No need!
      return $stats;
    }

    //
    // 4. OK, now we just simply pick the first non-deleted contact.
    //
    $civicrm_subscription_group_id = $this->getSubscriptionGroup();

    $sql = "
      UPDATE civicrm_mailchimpsync_cache mc
        INNER JOIN (
          SELECT e.email, MIN(e.contact_id) contact_id
            FROM civicrm_email e
            INNER JOIN civicrm_contact c1 ON e.contact_id = c1.id AND NOT c1.is_deleted
          GROUP BY e.email
        ) c ON c.email = mc.mailchimp_email
        SET mc.civicrm_contact_id = c.contact_id
        WHERE mc.civicrm_contact_id IS NULL
              AND c.contact_id IS NOT NULL
              AND mc.mailchimp_list_id = %1
      ";
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$this->mailchimp_list_id, 'String']]);
    $stats['used_first_undeleted_contact'] = $dao->affectedRows();
    $stats['remaining'] -= $stats['used_first_undeleted_contact'];

    // Remaining contacts are new to CiviCRM.
    // Create them now.
    return $stats;
  }
  /**
   * Create contacts found at Mailchimp but not in CiviCRM.
   *
   * Call this after calling populateMissingContactIds()
   *
   * Nb. if someone comes in from Mailchimp who is not subscribed there's no
   * point us adding them in. Instead we remove them from the cache table.
   *
   * @param null|int time in seconds to spend.
   * @return int No. contacts created.
   */
  public function createNewContactsFromMailchimp($remaining_time=NULL) {

    $this->updateLock([
      'for'    => 'fetchAndReconcile',
      'to'     => 'busy',
      'andLog' => 'createNewContactsFromMailchimp: beginning.'
    ]);
    $total = 0;
    $dao = CRM_Core_DAO::executeQuery(
      'SELECT * FROM civicrm_mailchimpsync_cache WHERE mailchimp_list_id = %1 AND civicrm_contact_id IS NULL',
      [ 1 => [$this->mailchimp_list_id, 'String'] ]
    );
    $stop_time = time() + ($remaining_time ?? 60*60*24*7);

    while ($dao->fetch()) {
      $id = (int) $dao->id;

      if ($dao->mailchimp_status !== 'subscribed') {
        // Only import contacts that are subscribed.
        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_mailchimpsync_cache WHERE id = $id");
        continue;
      }
      $total++;

      // Create contact.
      $params = [
        'contact_type' => 'Individual',
        'email'        => $dao->mailchimp_email,
      ];

      // If we have their name, use it to create contact.
      $mailchimp_data = unserialize($dao->mailchimp_data);
      foreach (['first_name', 'last_name'] as $field) {
        if (!empty($mailchimp_data[$field])) {
          $params[$field] = $mailchimp_data[$field];
        }
      }

      $contact_id = (int) civicrm_api3('Contact', 'create', $params)['id'];

      CRM_Core_DAO::executeQuery("UPDATE civicrm_mailchimpsync_cache SET civicrm_contact_id = $contact_id WHERE id = $id");
      if (time() >= $stop_time) {
        // Time's up, but we're not finished.
        $this->updateLock([
          'for'    => 'fetchAndReconcile',
          'is'     => 'readyToCreateNewContactsFromMailchimp',
          'andLog' => "createNewContactsFromMailchimp: Added $total new contacts found in Mailchimp but not CiviCRM but out of time - more to do.",
        ]);
        return $total;
      }
    }
    // Completed!
    $this->updateLock([
      'for'    => 'fetchAndReconcile',
      'is'     => 'readyToAddCiviOnly',
      'andLog' => "createNewContactsFromMailchimp: Added $total new contacts found in Mailchimp but not CiviCRM. No more to do.",
    ]);
    return $total;
  }
  /**
   *
   * If there are any contacts Added to the subscription group in CiviCRM, but
   * not known to Mailchimp, add them to the cache table.
   *
   * @param array $params
   */
  public function addCiviOnly() {
    $this->updateLock([
      'for'    => 'fetchAndReconcile',
      'is'     => 'busy',
      'andLog' => "addCiviOnly: Adding contacts found in CiviCRM but not in Mailchimp.",
    ]);
    $civicrm_subscription_group_id = $this->getSubscriptionGroup();

    $sql = "
      INSERT INTO civicrm_mailchimpsync_cache (mailchimp_list_id, civicrm_contact_id, sync_status)
        SELECT %1 mailchimp_list_id, contact_id, 'todo' sync_status
      FROM civicrm_group_contact gc
      WHERE gc.group_id = $civicrm_subscription_group_id
            AND gc.status = 'Added'
            AND NOT EXISTS (
              SELECT id FROM civicrm_mailchimpsync_cache mc2
              WHERE mc2.mailchimp_list_id = %1 AND civicrm_contact_id = gc.contact_id
          )
    ";
    $dao = CRM_Core_DAO::executeQuery($sql, [
      1 => [$this->mailchimp_list_id, 'String']
    ]);
    $total = $dao->affectedRows();
    $this->updateLock([
      'for'    => 'fetchAndReconcile',
      'is'     => 'readyToCopyCiviGroupStatus',
      'andLog' => "addCiviOnly: Added $total contacts found in CiviCRM but not in Mailchimp.",
    ]);
    return $total;
  }

  /**
   * Look up the group status and store it in the cache table.
   *
   * Operates on entries with sync_status 'todo'
   *
   * As a bulk SQL operation, this will be faster than querying contacts one at a time.
   */
  public function copyCiviCRMSubscriptionGroupStatus($since) {
    $this->updateLock([
      'for'    => 'fetchAndReconcile',
      'is'     => 'busy',
      'andLog' => "copyCiviCRMSubscriptionGroupStatus: Copying CiviCRM's subscription group update dates " . ($since ? "since $since" : ''),
    ]);

    $params = [
      1 => [$this->getSubscriptionGroup(), 'Integer'],
      2 => [$this->getListId(), 'String'],
    ];

    if ($since) {
      // Only operate on stuff we know we have to do or has been changed in
      // CiviCRM since the given since date.
      $where = 'AND (cache.sync_status = "todo" OR latest.date >= %3)';
      $params[3] = [date('YmdHis', strtotime($since)), 'String'];
    }
    else {
      $where = '';
    }

    $sql = '
      UPDATE civicrm_mailchimpsync_cache cache
        LEFT JOIN (
          SELECT contact_id, status, date
            FROM civicrm_subscription_history h1
           WHERE h1.group_id = %1
                 AND NOT EXISTS (
                   SELECT id FROM civicrm_subscription_history h2
                   WHERE h2.group_id = %1
                         AND h2.contact_id = h1.contact_id
                         AND h2.date > h1.date
                )
        ) latest ON cache.civicrm_contact_id = latest.contact_id
        SET civicrm_status = latest.status, civicrm_updated = latest.date, sync_status = "todo"
        WHERE cache.mailchimp_list_id = %2 ' . $where;
    CRM_Core_DAO::executeQuery($sql, $params);

    $this->updateLock([
      'for'    => 'fetchAndReconcile',
      'is'     => 'readyToReconcileQueue',
      'andLog' => "copyCiviCRMSubscriptionGroupStatus: complete.",
    ]);
  }
  // The following methods deal with the 'reconciliation' phase
  /**
   * Loop 'todo' entries and reconcile them.
   *
   * @param int $max_time If >0 then stop if we've been running longer than
   * this many seconds. This is useful for http driven cron, for exmaple.
   */
  public function reconcileQueueProcess(int $max_time=0) {
    $this->updateLock([
      'for'    => 'fetchAndReconcile',
      'is'     => 'busy',
      'andLog' => 'reconcileQueueProcess beginning',
    ]);
    $stop_time = ($max_time > 0) ? time() + $max_time : FALSE;

    $dao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $dao->mailchimp_list_id = $this->getListId();
    $dao->sync_status = 'todo';
    $ids = CRM_Core_DAO::executeQuery(
      'SELECT id FROM civicrm_mailchimpsync_cache WHERE sync_status = "todo" AND mailchimp_list_id = %1',
      [1 => [$this->getListId(), 'String']]
    )->fetchMap('id', 'id');

    $done = 0;
    foreach ($ids as $id) {
      if ($stop_time && (time() > $stop_time)) {
        // Time to stop.
        break;
      }
      $dao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
      $dao->id = $id;
      $dao->find(TRUE);
      $this->reconcileQueueItem($dao);
      $done++;
    }

    $stats = ['done' => $done, 'count' => count($ids)];

    if ($stats['done'] == $stats['count']) {
      $this->updateLock([
        'for'    => 'fetchAndReconcile',
        'is'     => 'readyToSubmitUpdates', // reset for next time.
        'andLog' => "reconcileQueueProcess: Completed reconciliation of $stats[done] contacts.",
        'andAlso' => function(&$c) { unset($c['fetch']['since']); }
      ]);
    }
    else {
      $this->updateLock([
        'for'    => 'fetchAndReconcile',
        'is'     => 'readyToReconcileQueue',
        'andLog' => "reconcileQueueProcess: Reconciled $stats[done], " . ($stats['count'] - $stats[done]) . " remaining but ran out of time.",
      ]);
    }
    return $stats;
  }
  /**
   * Reconcile a single item from the cache table.
   *
   * This will result in the item having status 'ok', or 'live' if mailchimp updates are queued.
   *
   * @param CRM_Mailchimpsync_DAO_MailchimpsyncCache $dao
   */
  public function reconcileQueueItem(CRM_Mailchimpsync_BAO_MailchimpsyncCache $contact) {

    $mailchimp_updates = [];

    try {
      $this->reconcileSubscriptionGroup($mailchimp_updates, $contact);
      // @todo other reconcilation operations.

      if ($mailchimp_updates) {
        $contact->sync_status = 'live';
        $contact->save();

        // Queue a mailchimp update.
        $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
        $update->mailchimpsync_cache_id = $contact->id;
        $update->data = json_encode($mailchimp_updates);
        $update->save();
      }
      else {
        // Updates were not needed or have all been done on the CiviCRM side.
        $contact->sync_status = 'ok';
        $contact->save();
      }
    }
    catch (CRM_Mailchimpsync_CannotSyncException $e) {
      // This contact cannot be sync'ed
      Civi::log()->warning("Marking contact $contact->civicrm_contact_id as failed Mailchimpsync status: " . $e->getMessage());
      $contact->sync_status = 'fail';
      $contact->save();
    }


  }

  /**
   * Ensure we have CiviCRM's subscription group membership in sync with Mailchimp's.
   *
   * @param &array $mailchimp_updates
   * @param CRM_Mailchimpsync_BAO_MailchimpsyncCache $contact
   */
  public function reconcileSubscriptionGroup(&$mailchimp_updates, CRM_Mailchimpsync_BAO_MailchimpsyncCache $contact) {

    if ($contact->subscriptionMostRecentlyUpdatedAtMailchimp()) {
      // Exists in Mailchimp and Mailchimp has been updated since CiviCRM was,
      // at least in terms of the subscription group, or the contact has no group
      // subscription history.

      if ($contact->isSubscribedAtCiviCRM()) {
        // Added in CiviCRM.

        if ($contact->isSubscribedAtMailchimp()) {
          // Subscribed (or nearly subscribed) at both ends.
          // No subscription group level changes needed.
        }
        else {
          // Mailchimp has unsubscribed/cleaned/archived this contact
          // (or, converted it to transactional - not sure if that happens)
          // So we need to remove this contact from the subscription group.
          $contact->unsubscribeInCiviCRM($this);
        }
      }
      else {
        // Removed, Deleted, or no subscription history in CiviCRM
        if ($contact->isSubscribedAtMailchimp()) {
          $contact->subscribeInCiviCRM($this);
        }
        else {
          // Not in subscription group and not in CiviCRM's either: subscription is in sync.
        }
      }
    }
    else {
      // Either does not exist in Mailchimp yet, or does but CiviCRM is more up-to-date
      // in terms of its subscription group.
      if ($contact->isSubscribedAtCiviCRM()) {
        if ($contact->isSubscribedAtMailchimp()) {
          // in sync.
        }
        else {
          switch ($contact->mailchimp_status) {
          case null:
            // Find best email to use to subscribe this contact.
            $mailchimp_updates['email_address'] = $this->getBestEmailForNewContact($contact->civicrm_contact_id);
            // deliberate fall through..

          case 'unsubscribed':
          case 'archived':
          case 'transactional':
            $mailchimp_updates['status'] = 'subscribed';
            break;

          case 'cleaned':
          default:
          // We will not be able to subscribe this person.
          throw new CRM_Mailchimpsync_CannotSyncException("Contact 'cleaned' by mailchimp");
            break;
          }
        }
      }
      else {
        // Not subscribed in CiviCRM.
        if ($contact->isSubscribedAtMailchimp()) {
          // Is subscribed at Mailchimp but should not be.
          $mailchimp_updates['status'] = 'unsubscribed';
        }
        else {
          // Not subscribed at Mailchimp or Civi, so already in-sync.
        }
      }
    }
  }

  // The following methods deal with the batching phase.
  /**
   *
   * Look for updates, submit up to 1000 at a time to the API.
   *
   * @return int number of requests sent.
   */
  public function submitUpdatesBatches($remaining_time) {
    $stop_time = time() + $remaining_time;
    $this->updateLock([
      'for' => 'fetchAndReconcile',
      'is' => 'busy',
      'andLog' => 'submitUpdatesBatches: Beginning to batch up any updates...',
    ]);

    $grand_total = 0;
    $batches = 0;
    do {
      $count = $this->submitBatch();
      $grand_total += $count;
      if ($count) {
        $batches++;
      }
    } while (time() < $stop_time && $count > 0);

    if ($count == 0) {
      $this->updateLock([
        'for'    => 'fetchAndReconcile',
        'is'     => 'readyToFetch',
        'andLog' => "submitUpdatesBatches: Completed submission of $batches batches with $grand_total updates...",
      ]);
      return ['batches' => $batches, 'total_operations' => $grand_total, 'is_complete' => 1];
    }
    else {
      $this->updateLock([
        'for'    => 'fetchAndReconcile',
        'is'     => 'readyToSubmitUpdates',
        'andLog' => "submitUpdatesBatches: Out of time. $batches batches with $grand_total updates were submitted.",
      ]);
      return ['batches' => $batches, 'total_operations' => $grand_total, 'is_complete' => 0];
    }
  }

  /**
   *
   * Look for updates, submit up to 1000 at a time to the API.
   *
   * @return int number of requests sent.
   */
  public function submitBatch() {
    $sql = "SELECT up.*, cache.mailchimp_email
      FROM civicrm_mailchimpsync_update up
        INNER JOIN civicrm_mailchimpsync_cache cache
          ON up.mailchimpsync_cache_id = cache.id
             AND cache.sync_status = 'live'
             AND cache.mailchimp_list_id = %1
      WHERE up.mailchimpsync_batch_id IS NULL
      LIMIT 1000";
    $params = [1 => [$this->mailchimp_list_id, 'String']];
    $dao = CRM_Core_DAO::executeQuery($sql, $params);

    $requests = [];
    $url_stub = "/lists/$this->mailchimp_list_id/members";
    $api = $this->getMailchimpApi();

    // Create requests
    while ($dao->fetch()) {
      // Updates are stored with a degree of normalisation.
      // We need to add in details and construct the URL.
      $id = $dao->id;
      $data = json_decode($dao->data, TRUE);

      $requests[$id] = [
        'operation_id' => 'mailchimpsync_' . $id,
      ];

      if (!$dao->mailchimp_email) {
        // Contact was not found on Mailchimp. The $data should already
        // include the email address.
        $requests[$id] += [
          'method'       => 'POST',
          'path'         => $url_stub,
        ];
      }
      else {
        // Contact is already known at mailchimp, so we use mailchimp's email.
        $data['email_address'] = $dao->mailchimp_email;
        $requests[$id] += [
          'method'       => 'PUT',
          'path'         => "$url_stub/" . $api->getMailchimpMemberIdFromEmail($dao->mailchimp_email),
        ];
      }

      $requests[$id]['body'] = json_encode($data);

    }
    if ($requests) {
      // @todo consider small batches to be processed directly.
      $mailchimp_batch_id = $api->submitBatch($requests);

      // Create a batch record.
      $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
      $batch->mailchimp_batch_id = $mailchimp_batch_id;
      $batch->mailchimp_list_id = $this->mailchimp_list_id;
      // $batch->submitted_at = date('Y-m-d H:i:s');
      $batch->total_operations = count($requests);
      $batch->save();

      // Update the updates table to 'claim' these records under this batch.
      $batch_id = (int) $batch->id;
      $sql = "UPDATE civicrm_mailchimpsync_update SET mailchimpsync_batch_id = $batch_id
              WHERE id IN (" . implode(',', array_keys($requests)) .");";
      CRM_Core_DAO::executeQuery($sql);
    }

    return count($requests);
  }

  // utility methods
  /**
   * Get the status array for this audience.
   *
   * @param bool $reset_cache
   */
  public function getStatus($reset_cache=FALSE) {
    if ($reset_cache || !$this->status_cache) {
      $status = CRM_Core_DAO::executeQuery(
        'SELECT data FROM civicrm_mailchimpsync_status WHERE list_id = %1',
        [1 => [$this->mailchimp_list_id, 'String']])->fetchValue();

      if ($status) {
        $status = unserialize($status);
      }
      if (!$status) {
        // Create initial default status.
        $status = [
          'lastSyncTime' => NULL,
          'locks' => ['fetchAndReconcile' => 'readyToFetch'],
          'log'   => [],
          'fetch' => [],
        ];

        // Save to the database so we can rely on it being there when we update it.
        CRM_Core_DAO::executeQuery(
          'INSERT INTO civicrm_mailchimpsync_status VALUES(%1, %2);',
          [
            1 => [$this->mailchimp_list_id, 'String'],
            2 => [serialize($status), 'String']
          ]);

      }
      $this->status_cache = $status;
    }

    return $this->status_cache;
  }
  /**
   * Fetch some stats for this list.
   *
   */
  public function getStats() {

    // Count of sync statuses.
    $params = [1 => [$this->mailchimp_list_id, 'String']];

    $sql = "SELECT sync_status, COALESCE(mailchimp_status, 'missing') mailchimp_status, COALESCE(civicrm_status, 'missing') civicrm_status, COUNT(*) c
      FROM civicrm_mailchimpsync_cache
      WHERE mailchimp_list_id = %1
      GROUP BY sync_status, mailchimp_status, civicrm_status";
    $result = CRM_Core_DAO::executeQuery($sql, $params)->fetchAll();
    $stats = [
      'failed' => 0,
      'subscribed_at_mailchimp' => 0,
      'subscribed_at_civicrm' => 0,
      'to_add_to_mailchimp' => 0,
      'cannot_subscribe' => 0,
      'to_remove_from_mailchimp' => 0,
      'weird' => [],
    ];
    foreach ($result as $row) {
      if ($row['sync_status'] === 'fail') {
        if ($row['civicrm_status'] === 'Added') {
          $stats['cannot_subscribe'] += $row['c'];
          $stats['subscribed_at_civicrm'] += $row['c'];
        }
        else {
          $stats['failed'] += $row['c'];
        }
      }
      elseif ($row['sync_status'] === 'live') {
        // This means we're waiting to update mailchimp

        if ($row['civicrm_status'] === 'Added') {
          $stats['subscribed_at_civicrm'] += $row['c'];

          if ($row['mailchimp_status'] !== 'subscribed') {
            $stats['to_add_to_mailchimp'] += $row['c'];
          }
          else {
            $stats['weird'][] = $row;
          }
        }
        else {
          // not added at civi, and updating mailchimp? Must be an unsubscribe.
          $stats['to_remove_from_mailchimp'] += $row['c'];
        }
      }
      elseif ($row['sync_status'] === 'ok') {
        // In sync, both subscribed or both unsubscribed
        if ($row['civicrm_status'] === 'Added') {
          // Both subscribed.
          $stats['subscribed_at_civicrm'] += $row['c'];
          $stats['subscribed_at_mailchimp'] += $row['c'];
        }
        else {
          $stats['weird'][] = $row;
        }
      }
      else {
          $stats['weird'][] = $row;
      }
    }

    $sql = 'SELECT COUNT(*) c FROM civicrm_mailchimpsync_update up INNER JOIN civicrm_mailchimpsync_cache c ON c.mailchimp_list_id = %1 AND up.mailchimpsync_cache_id = c.id
      WHERE up.completed = 0';
    $stats['mailchimp_updates_pending'] = (int) CRM_Core_DAO::executeQuery($sql, $params)->fetchValue();

    $sql = 'SELECT COUNT(*) c FROM civicrm_mailchimpsync_update up INNER JOIN civicrm_mailchimpsync_cache c ON c.mailchimp_list_id = %1 AND up.mailchimpsync_cache_id = c.id
      WHERE up.completed = 0 AND up.mailchimpsync_batch_id IS NULL';
    $stats['mailchimp_updates_unsubmitted'] = (int) CRM_Core_DAO::executeQuery($sql, $params)->fetchValue();

    return $stats;
  }
  /**
   * Fetches the appropriate API object for this list.
   *
   * @return CRM_Mailchimpsync_Audience
   */
  public function getMailchimpApi() {
    return CRM_Mailchimpsync::getMailchimpApi($this->config['apiKey']);
  }
  /**
   * Finds the best email address
   *
   * 'bulk mail' wins, then primary, then any old one.
   *
   *
   * @throws InvalidArgumentException if can't find an email.
   * @param int $contact_id
   * @return string email address
   */
  public function getBestEmailForNewContact($contact_id) {
   $sql = "SELECT email
              FROM civicrm_email e
             WHERE contact_id = %1 AND e.on_hold = 0 AND email IS NOT NULL AND email != ''
          ORDER BY is_bulkmail DESC, is_primary DESC
             LIMIT 1";
    $params = [1 => [$contact_id, 'Integer']];
    $email = CRM_Core_DAO::executeQuery($sql, $params)->fetchValue();
    if (!$email) {
      throw new CRM_Mailchimpsync_CannotSyncException("Failed to find email address for contact $contact_id");
    }
    return $email;
  }
  public function log($message) {
    $log = ['time' => date('Y-m-d H:i:s'), 'message' => $message];
    // Update the config.
    $this->updateAudienceStatusSetting(function(&$config) use ($log) {
      $config['log'][] = $log;
    });
  }
  /**
   * Update setting.
   *
   * @param callable $callback
   *        This must take &$config as a parameter and alter it as needed.
   *        If no changes are needed it should return FALSE
   */
  public function updateAudienceStatusSetting(Callable $callback) {

    // Lock tables and ensure we have the latest data from db.
    //CRM_Core_DAO::executeQuery("LOCK TABLES civicrm_setting WRITE, civicrm_recurring_entity READ;");
    $config = $this->getStatus(TRUE);

    // Alter config via callback.
    $changed = $callback($config);

    if ($changed !== FALSE) {
      // Store the changed status in settings.
      CRM_Core_DAO::executeQuery(
        'UPDATE civicrm_mailchimpsync_status SET data = %1 WHERE list_id = %2',
        [
          1 => [serialize($config), 'String'],
          2 => [$this->mailchimp_list_id, 'String']
        ]);

      $this->status_cache = $config;
    }

    // Unlock tables.
    //CRM_Core_DAO::executeQuery("UNLOCK TABLES;");
    return $this;
  }
  /**
   * Attempt to obtain a lock for this audience.
   *
   * @param array $params with keys:
   * 'for' - the type of lock requested
   * 'to'  - the value to set the lock to
   * 'if'  - the lock will only be granted if the current lock status is this
   *         (or there is no such lock)
   * @return bool TRUE if lock obtained.
   */
  public function attemptToObtainLock($params) {
    $lock_obtained = FALSE;
    $purpose = $params['for'];
    $required_status = $params['if'];
    $intent = $params['to'];
    $this->updateAudienceStatusSetting(function(&$status) use ($purpose, $required_status, $intent, &$lock_obtained){

      if (!isset($status['locks'][$purpose])
        || $status['locks'][$purpose] === $required_status
      ) {
        // Ok, we can lock it.
        $status['locks'][$purpose] = $intent;
        $lock_obtained = TRUE;
      }
      else {
        // No changes to be made.
        return FALSE;
      }
    });
    return $lock_obtained;
  }
  /**
   * Update a lock.
   *
   * @param array with keys:
   * - for: the type of lock.
   * - is: the final state.
   * - andLog: log message (optional).
   * - andAlso: callback (optional).
   */
  public function updateLock(array $params) {
    $purpose = $params['for'];
    $ready = $params['is'];
    $message = $params['andLog'] ?? NULL;
    $andAlso = $params['andAlso'] ?? NULL;

    $this->updateAudienceStatusSetting(function(&$config) use ($purpose, $ready, $message, $andAlso) {
      $config['locks'][$purpose] = $ready;
      if ($message) {
        $config['log'][] = ['time' => date('Y-m-d H:i:s'), 'message' => $message];
      }
      if (is_callable($andAlso)) {
        $andAlso($config);
      }
    });
  }
}
