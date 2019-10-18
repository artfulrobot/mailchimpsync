<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

/**
 * MailchimpsyncBatch.create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_mailchimpsync_batch_create_spec(&$spec) {
  // $spec['some_parameter']['api.required'] = 1;
}

/**
 * MailchimpsyncBatch.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_batch_create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailchimpsyncBatch.delete API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_batch_delete($params) {
  return _civicrm_api3_basic_delete(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}

/**
 * MailchimpsyncBatch.get API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_batch_get($params) {
  return _civicrm_api3_basic_get(_civicrm_api3_get_BAO(__FUNCTION__), $params);
}
/**
 * MailchimpsyncBatch.process
 */
function civicrm_api3_mailchimpsync_batch_processcompleted_spec(&$spec) {

  $spec['id'] = [
    'description'  => 'The civicrm_mailchimpsync_batch.id of the batch to process',
    'type'         => CRM_Utils_Type::T_INT,
    'api.required' => TRUE,
  ];
  $spec['force'] = [
    'description'  => 'Process even if it looks like processing is underway/has been done already.',
    'type'         => CRM_Utils_Type::T_BOOLEAN,
  ];
}
/**
 * MailchimpsyncBatch.process
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_mailchimpsync_batch_processcompleted($params) {
  if (empty($params['id'])) {
    throw new API_Exception('MailchimpsyncBatch.processCompleted requires id parameter');
  }
  $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
  $bao->id= $params['id'];
  if (!$bao->find(1)) {
    throw new API_Exception('MailchimpsyncBatch.processCompleted given id not found');
  }
  try {
    $returnValues = $bao->processCompletedBatch(NULL, (!empty($params['force'])));
  }
  catch (InvalidArgumentException $e) {
    throw new API_Exception($e->getMessage());
  }
  return civicrm_api3_create_success($returnValues, $params, 'MailchimpsyncBatch', 'processCompleted');
}
