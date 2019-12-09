<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_BAO_MailchimpsyncBatch extends CRM_Mailchimpsync_DAO_MailchimpsyncBatch {

  /**
   * Called when we think a batch has completed.
   *
   * @param array|null $batch_status if not given, the batch status is fetched from the api
   * @param bool $force If true, we don't check that the batch has not been processed already.
   * @throws InvalidArgumentException if the batch has not finished.
   */
  public function processCompletedBatch($batch_status=NULL, $force=FALSE) {

    // Check if processing is already underway.
    if (!$force && $this->response_processed > 0) {
      throw new InvalidArgumentException("Batch $this->id ($this->mailchimp_batch_id) "
        . (($this->response_processed == 1) ? 'currently being' : 'already')
        . ' processed. Use the force parameter if you want to reprocess this.'
        );
    }

    // First, fetch the batch status from the API. This ensures we download
    // from the correct URL. Without this, a spammer could POST malicious URLs
    // to our endpoint which we would then download and parse.

    $audience = CRM_Mailchimpsync_Audience::newFromListId($this->mailchimp_list_id);
    $api = $audience->getMailchimpApi();

    if (!$batch_status) {
      // Load the data from the API now.
      $batch_status = $api->get("batches/$this->mailchimp_batch_id");
    }

    $status = ($batch_status['status'] ?? '');
    if ($status !== 'finished') {
      throw new InvalidArgumentException("Batch $this->mailchimp_batch_id has not finished. Got status: $status");
    }

    // Store the fact that we're starting processing.
    $this->response_processed = 1;
    $this->save();

    // OK, download the resource.
    $tar_filename = $api->downloadBatchResponse($batch_status['response_body_url']);

    try {
      $untar = new CRM_Mailchimpsync_UnMailchimpTar($tar_filename);
      do {
        $file = $untar->getNextFile();
        if ($file) {
          $this->processBatchFile($file);
        }
      } while ($file);

      // Update our batch record.
      $this->completed_at = date('YmdHis', strtotime($batch_status['completed_at']));
      $this->submitted_at = date('YmdHis', strtotime($batch_status['submitted_at']));
      $this->finished_operations = $batch_status['finished_operations'];
      $this->status = $batch_status['status'];
      $this->errored_operations = $batch_status['errored_operations'];
      $this->total_operations = $batch_status['total_operations'];
      $this->response_processed = 2;
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
      $matches = NULL;
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
