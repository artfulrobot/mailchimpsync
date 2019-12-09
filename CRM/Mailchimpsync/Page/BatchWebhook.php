<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_Page_BatchWebhook extends CRM_Core_Page {

  public function run() {
    if ($_POST) {
      if (CRM_Mailchimpsync::batchWebhookKeyIsValid($_GET['secret'])) {
        try {
          $this->processWebhook($_POST);
        }
        catch (CRM_Mailchimpsync_BatchWebhookNotRelevantException $e) {
          // Softer more expected error. As far as Mailchimp is concerned this
          // is fine, we serve a 200 OK response.
          Civi::log()->info($e->getMessage() . "\n" . json_encode($_POST));
        }
        catch (Exception $e) {
          // All other errors.
          Civi::log()->error("Exception processing Mailchimp batch webhook.\n"
            . json_encode([
              'exception' => $e,
              'data' => json_encode($_POST),
              'trace' => $e->getTraceAsString(),
            ], JSON_PRETTY_PRINT));

          // Let Mailchimp know that didn't work.
          http_response_code(500);
        }
      }
      else {
        // Forbidden.
        http_response_code(401);
      }
    }
    else {
      // I think Mailchimp uses a GET request to validate the endpoint URL.
      echo "OK";
    }
    CRM_Utils_System::civiExit();
  }

  /**
   *
   * @throw CRM_Mailchimpsync_BatchWebhookNotRelevantException if it doesn't look relevant to us.
   * @throw InvalidArgumentException if something looks wrong.
   */
  public function processWebhook($data) {
    if (!is_array($data)) {
      throw new InvalidArgumentException("Data missing or not array");
    }
    if ((($data['type'] ?? '') === "batch_operation_completed")) {
      if (preg_match('/^[0-9a-f]{10}$/', $data['data']['id'] ?? '')) {
        // OK, looks possibly legit.
        $batch = new CRM_Mailchimpsync_BAO_MailchimpsyncBatch();
        $batch->mailchimp_batch_id = $data['data']['id'];
        if (!$batch->find(1)) {
          throw new CRM_Mailchimpsync_BatchWebhookNotRelevantException("Batch ID not one we are tracking");
        }
        $batch->processCompletedBatch();
      }
      else {
        throw new InvalidArgumentException("Batch ID not in expected format.");
      }
    }
    else {
      throw new CRM_Mailchimpsync_BatchWebhookNotRelevantException("Ignoring webhook as it is not batch_operation_completed");
    }
  }

}
