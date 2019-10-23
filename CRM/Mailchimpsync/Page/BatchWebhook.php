<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_Page_BatchWebhook extends CRM_Core_Page {

  public function run() {
    if ($_POST) {
      if (CRM_Mailchimpsync::batchWebhookKeyIsValid($_GET['secret'])) {
        $exit_status = $this->processWebhook($_POST);
      }
      else {
        // Forbidden.
        $exit_status = 401;
      }
      CRM_Utils_System::civiExit($exit_status);
    }
    else {
      echo "OK";
      CRM_Utils_System::civiExit();
    }
  }

  public function processWebhook($data) {
    try {
      if (!is_array($data)) {
        throw new Exception("Data missing or not array");
      }
      if ((($data['type'] ?? '') === "batch_operation_completed")
        && preg_match('/^[0-9a-f]{10}$/', $data['data']['id'] ?? '')) {

        // OK, looks possibly legit.
        $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
        $batch->mailchimp_batch_id = $data['data']['id'];
        if (!$batch->find(1)) {
          throw new InvalidArgumentException("Batch ID not one we are tracking");
        }
        $batch->processCompletedBatch();
      }
    }
    catch (InvalidArgumentException $e) {
      // Softer more expected error.
      Civi::log()->info($e->getMessage(), [ 'data' => $data ]);
    }
    catch (Exception $e) {
      // All other errors.
      Civi::log()->error("Exception processing Mailchimp batch webhook.",
        [ 'exception' => $e, 'data' => json_encode($data), 'trace' => $e->getTraceAsString() ]);
      return 500;
    }
    return 200;
  }

}
