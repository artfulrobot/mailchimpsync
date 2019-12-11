Tables used by this extension.

## `civicrm_mailchimpsync_batch`

This table holds one record per submitted batch of updates. Most of it's
fields hold data obtained from Mailchimp's batch status resposnes.

N.b. while a batch  update for Mailchimp may contain any API requests, the
way our system is designed it means each batch will only contain updates
on a particular audience. We store the audience ID in the
`mailchimp_list_id` field.

The `status` field may contain 'aborted' - Mailchimp won't ever report
this; it's something set manually by a call to Mailchimpsync.abortsync
(which is not a normal thing to do).

`response_processed` is either:

- *0* The response has not been received yet.
- *1* We've started processing the response.
- *2* We've finished processing the response.


## `civicrm_mailchimpsync_cache`

This table has one row per contact/member per sync connection. It holds
their status at Mailchimp and at CiviCRM.

- `mailchimp_member_id` is the md5 of the lowercase email address; this is
  how Mailchimp refers to emails. Yes, it's problematic - see [Mailchimp
  Issues](/discussion/mailchimp-issues.md). This won't be set until the
  member exists at Mailchimp.

- `mailchimp_status` one of
  subscribed|unsubscribed|cleaned|pending|transactional|archived

- `mailchimp_updated` the date that mailchimp says the member was last
  updated. This is super important in determining which system is right
  about whether someone should be subscribed.

- `mailchimp_data` PHP serialized data loaded from Mailchimp.

- `civicrm_groups` PHP serialized data loaded from CiviCRM with the
  datetimes that this contact was added/removed from each of the groups
  relevant to this audience. This is super important in determining which
  system is right about whether someone should be subscribed.

- `civicrm_data` PHP serialized data from CiviCRM.

- `civicrm_contact_id` This won't be set until we've figured it out. Once
  it's set, we assume it to be correct, but there is a check to make sure
  the contact still exists.

- `sync_status`

    - `ok`: At the end of a sync run, we considered that the two were in sync. If subsequently something changes at CiviCRM then it might be a lie.
    - `todo`: This has been identified as having changed since the 'since' date being used in a sync. It means it's live.
    - `redo`: We tried to subscribe them but couldn't because of a 'compliance state' so we're trying again using the Pending status.
    - `fail`: An update failed (or was aborted)

## `civicrm_mailchimpsync_update`

This stores the individual updates included (or to be included) in batches for
Mailchimp to process. `completed` is updated once it's completd, and if there's
an error it's stored in the `error_response` field

## `civicrm_mailchimpsync_status`

This holds a PHP serialized array of data used to store the sync status; one
row per audience. This is updated by
`CRM_Mailchimp::updateAudienceStatusSetting` which uses locks to ensure no two
processes do it at the same time.
