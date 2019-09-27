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
  // The following methods deal with the 'fetch' phase
  /**
   * Merge subscriber data form Mailchimp into our table.
   *
   * @param array $params with keys:
   * - since    Only load things changed since this date. (optional)
   */
  public function mergeMailchimpData(array $params=[]) {

    $api = $this->getMailchimpApi();

    $query = [
      'count' => CRM_Mailchimpsync_MailchimpApiBase::MAX_MEMBERS_COUNT,
      'offset' => 0,
    ];
    do {
      $response = $api->get("lists/$this->mailchimp_list_id/members", $query);

      // Fetch (filtered) data from our mock_mailchimp_data array.
      // Insert it into our cache table.
      foreach ($response['members'] ?? [] as $member) {
        $this->mergeMailchimpMember($member);
      }

      // Prepare to load next page.
      $query['offset'] += CRM_Mailchimpsync_MailchimpApiBase::MAX_MEMBERS_COUNT;

    } while ($response['total_items'] > $query['offset']);

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

    // Create JSON data from Mailchimp. @todo
    $data = [];
    $bao->mailchimp_data = json_encode($data);

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

    return $dao->affectedRows();
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
   * @return int No. contacts created.
   */
  public function createNewContactsFromMailchimp() {

    $total = 0;
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_list_id = $this->mailchimp_list_id;
    $bao->civicrm_contact_id = 'null';
    $bao->find();
    while ($bao->fetch()) {
      $total++;

      // Create contact.
      $params = [
        'contact_type' => 'Individual',
        'email' => $bao->mailchimp_email,
      ];

      // @todo names etc.

      $contact_id = civicrm_api3('Contact', 'create', $params)['id'];

      // Update.
      $bao->civicrm_contact_id = $contact_id;
      $bao->save();
    }

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
    return $dao->affectedRows();


    // Foreach contact Id we'll need to know whether there's a cache record for
    // it. If we did this in a loop we'd be creating N queries where N is the
    // number of contacts ever in the group.


    /*
    // Select the most recent subscription history line for each contact in the group.
    $sql = "SELECT contact_id, date, status FROM civicrm_subscription_history h
      WHERE group_id = $civicrm_subscription_group_id
      AND NOT EXISTS (
        SELECT id
          FROM civicrm_subscription_history h2
          WHERE h2.group_id = $civicrm_subscription_group_id
                AND h2.contact_id = h1.contact_id
                AND h2.date < h1.date
      )";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      if (!isset($known_contacts[$dao->contact_id])) {
        if ($dao->status === 'Added') {
          // Found a contact that needs to be created at Mailchimp.
        }
      }
    }

    // Instead we'll cache the contats in this list in RAM.
    $known_contacts = CRM_Core_DAO::executeQuery('SELECT civicrm_contact_id contact_id FROM civicrm_mailchimpsync_cache WHERE mailchimp_list_id = %1')
      ->fetchMap('contact_id', 'contact_id');

     */
  }

  /**
   * Look up the group status and store it in the cache table.
   *
   * Operates on entries with sync_status 'todo'
   *
   * As a bulk SQL operation, this will be faster than querying contacts one at a time.
   */
  public function copyCiviCRMSubscriptionGroupStatus() {
    $sql = '
      UPDATE civicrm_mailchimpsync_cache cache,
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
        ) latest ON cache.contact_id = latest.contact_id
        SET civicrm_status = latest.status, civicrm_changed = latest.date
        WHERE cache.mailchimp_list_id = %2 AND cache.sync_status = "todo"
    ';
    $params = [
      1 => [$this->getSubscriptionGroup(), 'Integer'],
      2 => [$this->getListId(), 'String'],
    ];
    CRM_Core_DAO::executeQuery($sql, $params);
  }
  // The following methods deal with the 'reconciliation' phase
  /**
   * Loop 'todo' entries and reconcile them.
   *
   * @param int $max_time If >0 then stop if we've been running longer than
   * this many seconds. This is useful for http driven cron, for exmaple.
   */
  public function reconcileQueueProcess(int $max_time=0) {
    $stop_time = ($max_time > 0) ? time() + $max_time : FALSE;


    $dao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $dao->mailchimp_list_id = $this->getListId();
    $dao->sync_status = 'todo';
    $count = $dao->find();

    $done = 0;

    while ($dao->fetch() && (!$stop_time || (time() < $stop_time))) {
      $this->reconcileQueueItem($dao);
    }

    return ['done' => $done, 'count' => $count];
  }
  /**
   * Reconcile a single item from the cache table.
   *
   * This is where the real work is done.
   *
   * @param CRM_Mailchimpsync_DAO_MailchimpsyncCache $dao
   */
  public function reconcileQueueItem(CRM_Mailchimpsync_BAO_MailchimpsyncCache $contact) {

    $mailchimp_updates = [];
    $this->reconcileSubscriptionGroup($mailchimp_updates, $contact);
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
          case 'unsubscribed':
          case 'archived':
          case 'transactional':
          case null:
            $mailchimp_updates['status'] = 'subscribed';
            break;

          case 'cleaned':
          default:
          // We will not be able to subscribe this person.
          // @todo issue warning.
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

  // utility methods
  /**
   * Fetches the appropriate API object for this list.
   *
   * @return CRM_Mailchimpsync_Audience
   */
  public function getMailchimpApi() {
    return CRM_Mailchimpsync::getMailchimpApi($this->config['apiKey']);
  }
}
