<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_BAO_MailchimpsyncBatch extends CRM_Mailchimpsync_DAO_MailchimpsyncBatch {

  /**
   * Called when we think a batch has completed.
   *
   * @throws InvalidArgumentException if the batch has not finished.
   */
  public function processCompletedBatch() {

    // First, fetch the batch status from the API. This ensures we download
    // from the correct URL. Without this, a spammer could POST malicious URLs
    // to our endpoint which we would then download and parse.

    $audience = CRM_Mailchimpsync_Audience::newFromListId($this->mailchimp_list_id);
    $api = $audience->getMailchimpApi();

    $data = $api->get("batches/$this->mailchimp_batch_id");
    $status = ($data['status'] ?? '');
    if ($status !== 'finished') {
      throw new InvalidArgumentException("Batch $this->mailchimp_batch_id has not finished. Got status: $status");
    }

    // OK, download the resource.
    $tar_filename = $api->downloadBatchResponse($data['response_body_url']);

    try {
      $untar = new CRM_Mailchimpsync_UnMailchimpTar($tar_filename);
      do {
        $file = $untar->getNextFile();
        if ($file) {
          $this->processBatchFile($file);
        }
      } while ($file);

      // Update our batch record.
      $this->completed_at = $data['completed_at'];
      $this->submitted_at = $data['submitted_at'];
      $this->finished_operations = $data['finished_operations'];
      $this->status = $data['status'];
      $this->errored_operations = $data['errored_operations'];
      $this->total_operations = $data['total_operations'];
      $this->save();
    }
    catch (Exception $e) {
      if (file_exists($tar_filename)) {
        unlink($tar_filename);
      }
      throw $e;
    }

    if (file_exists($tar_filename)) {
      unlink($tar_filename);
    }

  }
  /**
   * Process a response file.
   *
   * @param array $file with keys: filename, data
   */
  public function processBatchFile(array $file) {
    if (empty($file['data'])) {
      // Seems you get en empty file sometimes.
      return;
    }
    // Loop responses in this file.
    foreach ($file['data'] as $response) {
      $operation_id = $response['operation_id'] ?? '';
      if (!preg_match('/^mailchimpsync_(\d+)$/', $operation_id, $matches)) {
        // Odd, this does not look like it's for us.
        throw new InvalidArgumentException("Received unrecognised operation id in mailchimp batch: $this->mailchimp_batch_id, operation: $operation_id");
        return;
      }

      // Load the corresponding update.
      $update_id = (int) $matches[1];
      $update = new CRM_Mailchimpsync_BAO_MailchimpsyncUpdate();
      $update->id = $update_id;
      if (!$update->find(1)) {
        Civi::log()->error("Received operation id '$operation_id' but no match for this in civicrm_mailchimpsync_update table. Ignoring it.");
        continue;
      }

      // OK, handle the response now.
      $update->handleMailchimpUpdatesResponse($response);
    }
  }
}
