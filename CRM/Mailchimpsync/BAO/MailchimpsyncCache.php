<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

// @todo maybe log all changesinto a table? would need periodic clean out.
class CRM_Mailchimpsync_BAO_MailchimpsyncCache extends CRM_Mailchimpsync_DAO_MailchimpsyncCache {

  /**
   * Create a new MailchimpsyncCache based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Mailchimpsync_DAO_MailchimpsyncCache|NULL
   *
  public static function create($params) {
    $className = 'CRM_Mailchimpsync_DAO_MailchimpsyncCache';
    $entityName = 'MailchimpsyncCache';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */


  /**
   * Returns true if Mailchimp's last_changed date exceeds CiviCRM's, or if
   * Civi doesn't have one.
   *
   * @return bool
   */
  public function subscriptionMostRecentlyUpdatedAtMailchimp() {
    return $this->mailchimp_updated
           && (!$this->civicrm_updated
               || $this->mailchimp_updated > $this->civicrm_updated);
  }
  /**
   * Returns true if CiviCRM's last subscription group history date exceeds
   * Mailchimps, or contact not at Mailchimp.
   *
   * Nb. This also includes the (rare!) case that both are updated in the same
   * second.
   *
   * @return bool
   */
  public function subscriptionMostRecentlyUpdatedAtCiviCRM() {
    return ($this->civicrm_updated
            && (!$this->mailchimp_updated
                || $this->mailchimp_updated <= $this->civicrm_updated));
  }
  /**
   * Returns TRUE if we consider the person to be subscribed at Mailchimp.
   *
   * @return bool
   */
  public function isSubscribedAtMailchimp() {
    return (bool) ($this->mailchimp_status && in_array($this->mailchimp_status, ['subscribed', 'pending']));
  }
  /**
   * Returns TRUE if we consider the person to be subscribed at CiviCRM.
   *
   * // @todo does civi have a 'pending' status for double opt-in?
   * @return bool
   */
  public function isSubscribedAtCiviCRM() {
    return (bool) ($this->civicrm_status === 'Added');
  }
  /**
   * Set CiviCRM subscription group Added.
   *
   * @param CRM_Mailchimpsync_Audience $audience
   *
   * @return CRM_Mailchimpsync_BAO_MailchimpsyncCache
   */
  public function subscribeInCiviCRM(CRM_Mailchimpsync_Audience $audience) {
    $contacts = [$this->civicrm_contact_id];
    // Subscribe at CiviCRM.
    CRM_Contact_BAO_GroupContact::addContactsToGroup($contacts, $audience->getSubscriptionGroup(), 'MCsync');
    // Update (but do not save) our object.
    $this->civicrm_status = 'Added';

    return $this;
  }
  /**
   * Set CiviCRM subscription group status Removed.
   *
   * @param CRM_Mailchimpsync_Audience $audience
   *
   * @return CRM_Mailchimpsync_BAO_MailchimpsyncCache
   */
  public function unsubscribeInCiviCRM(CRM_Mailchimpsync_Audience $audience) {
    $contacts = [$this->civicrm_contact_id];
    // Record as Removed at CiviCRM.
    CRM_Contact_BAO_GroupContact::removeContactsFromGroup(
      $contacts, $audience->getSubscriptionGroup(), 'MCsync', 'Removed');
    // Update (but do not save) our object.
    $this->civicrm_status = 'Removed';

    return $this;
  }
}
