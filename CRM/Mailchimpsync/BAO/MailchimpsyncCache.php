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
   * Returns TRUE if we consider the person to be subscribed at Mailchimp.
   *
   * @return bool
   */
  public function isSubscribedAtMailchimp() {
    return (bool) ($this->mailchimp_status && in_array($this->mailchimp_status, ['subscribed', 'pending']));
  }
  /**
   * Set CiviCRM subscription group Added.
   *
   * @param CRM_Mailchimpsync_Audience $audience
   *
   * @return CRM_Mailchimpsync_BAO_MailchimpsyncCache
   */
  public function subscribeInCiviCRM(CRM_Mailchimpsync_Audience $audience) {
    if (!$this->civicrm_contact_id) {
      throw new Exception("Cannot subscribeInCiviCRM without knowing contact_id");
    }
    $contacts = [$this->civicrm_contact_id];
    // Subscribe at CiviCRM.
    CRM_Contact_BAO_GroupContact::addContactsToGroup($contacts, $audience->getSubscriptionGroup(), 'MCsync');
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
    if (!$this->civicrm_contact_id) {
      throw new Exception("Cannot unsubscribeInCiviCRM without knowing contact_id");
    }
    $contacts = [$this->civicrm_contact_id];
    // Record as Removed at CiviCRM.
    CRM_Contact_BAO_GroupContact::removeContactsFromGroup(
      $contacts, $audience->getSubscriptionGroup(), 'MCsync', 'Removed');
    return $this;
  }
  /**
   * Return a new object for the same record by reloading from database.
   *
   * @return CRM_Mailchimpsync_BAO_MailchimpsyncCache
   */
  public function reloadNewObjectFromDb() {
    $id = $this->id;
    $obj = new static();
    $obj->id = $id;
    $obj->find(TRUE);
    return $obj;
  }
}
