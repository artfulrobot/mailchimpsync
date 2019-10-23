<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_Page_Webhook extends CRM_Core_Page {

  public function run() {
    if (!CRM_Mailchimpsync::webhookKeyIsValid($_GET['secret'])) {
      CRM_Utils_System::civiExit(401);
    }

    // @todo sense check that this webhook does not have API as a cause.

    // Hand off to separate methods.
    $method = 'process' . ucfirst($_POST['type'] ?? 'undefined');
    if (method_exists($this, $method)) {
      $response_code = $this->$method($_POST);
    }

    CRM_Utils_System::civiExit($response_code);
  }

  /**
   * Handle subscribe requests
   */
  public function processSubscribe($data) {

  }

  /**
   * Handle unsubscribe requests
   */
  public function processUnsubscribe($data) {

  }

  /**
   * Handle profile update requests
   */
  public function processProfile($data) {

  }

  /**
   * Handle email update requests
   */
  public function processUpemail($data) {

  }


  /**
   * Handle email cleaned requests
   */
  public function processCleaned($data) {

  }

}
