<?php
use CRM_Mailchimpsync_ExtensionUtil as E;

class CRM_Mailchimpsync_BAO_MailchimpsyncUpdate extends CRM_Mailchimpsync_DAO_MailchimpsyncUpdate {

  /**
   * This handles a mailchimp response from a batch update.
   *
   * A successful response looks like:
   * { status_code: 200, response: {...} }
   *
   * An error response looks like:
   * { status_code: 400, response: {title: '', type: '', detail: ''} }
   *
   *
   * @param array $response
   */
  public function handleMailchimpUpdatesResponse(array $response) {

    // Civi::log()->info("handleMailchimpUpdatesResponse: " . json_encode($response));

    // Mark this update completed.
    $this->completed = 1;

    if ($response['status_code'] == 200) {
      $this->handleMailchimpUpdateSuccess($response['response']);
    }
    else {
      // Store the error
      $this->error_response = json_encode($response['response']);

      $mailchimp_updates = json_decode($this->data, TRUE);
      if ($response['status_code'] == 400
        && (($response['response']['title'] ?? '') === 'Member In Compliance State')
        && (($mailchimp_updates['status'] ?? '') === 'subscribed')) {

        // Very specific case:
        $this->handleMailchimpUpdateRetryPending($response, $mailchimp_updates);
      }
      else {
        $this->handleMailchimpUpdateFail($response);
      }
    }

    // Save our changes.
    $this->save();
  }
  public function handleMailchimpUpdateRetryPending(array $response, array $mailchimp_updates) {
    // If this was a subscribe, retry setting it to pending.
    // We leave the sync status as 'live' for now.
    $new_update = new static();
    $new_update->mailchimpsync_cache_id = $this->mailchimpsync_cache_id;
    $mailchimp_updates['status'] = 'pending';
    $new_update->data = json_encode($mailchimp_updates);
    $new_update->save();
    Civi::log()->info("Failed to subscribe via update $this->id but will try to set them as pending (update $new_update->id).");
  }
  public function handleMailchimpUpdateFail($response) {
    $cache = $this->getCacheBao();
    $cache->sync_status = 'fail';
    $cache->save();
  }
  /**
   * Mark this update complete and update sync cache.
   *
   * We can mark the sync cache as OK if there are no other live updates pointing to the cache record.
   *
   * @param array $response - Mailchimp response data.
   */
  public function handleMailchimpUpdateSuccess(array $response) {

    $cache = $this->getCacheBao();
    // Update the cache item.
    $updated = FALSE;
    if (!$cache->mailchimp_email) {
      // The cache has no mailchimp email, which happens when a contact was added at CiviCRM.
      // Set this now to improve efficience of next sync.
      $cache->mailchimp_email = $response['email_address'];
      $cache->mailchimp_member_id = $response['id'];
      $updated = TRUE;
    }

    // Update status. @todo issue #5
    $cache->mailchimp_status = $response['status'];
    $cache->mailchimp_updated = date('YmdHis', strtotime($response['last_changed']));

    // Are there any other updates pending for our contact?
    $other = new static();
    $other->mailchimpsync_cache_id = $this->mailchimpsync_cache_id;
    $other->completed = 0;
    if ($other->count() === 1) {
      // No, just this, and we're all done so we can mark it OK.
      $cache->sync_status = 'ok';
      $updated = TRUE;
    }
    if ($updated) {
      $cache->save();
    }
  }
  /**
   *
   * @return CRM_Mailchimpsync_BAO_MailchimpsyncCache
   */
  public function getCacheBao() {
    // Find the cache record.
    $cache = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $cache->id = $this->mailchimpsync_cache_id;
    if (!$cache->find(1)) {
      throw new \InvalidArgumentException("Cache record with ID: $this->mailchimpsync_cache_id has been deleted but the update has been completed.");
    }
    return $cache;
  }
}
