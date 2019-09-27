<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_BAO_MailchimpsyncUpdates extends CRM_Mailchimpsync_DAO_MailchimpsyncUpdates {

  /**
   * Create a new MailchimpsyncUpdates based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Mailchimpsync_DAO_MailchimpsyncUpdates|NULL
   *
  public static function create($params) {
    $className = 'CRM_Mailchimpsync_DAO_MailchimpsyncUpdates';
    $entityName = 'MailchimpsyncUpdates';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}
