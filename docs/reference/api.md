Technical reference to the API.

## Mailchimpsync.fetchandreconcile

### `force_restart` parameter (boolean)

This is for emergency troubleshooting and should not normally be used since it basically destroys the tracking data used to monitor sync processes. It does *not* abort any sync processes that may be running (although it might cause them to crash), and it can leave your data in a mess. See also `Mailchimpsync.abortsync`

### `group_id` parameter (integer) (`id` is alised to this)

Without this, sync is run for each list in turn. With this given, sync will only run for that group. (The group has to be a subscription sync; this is not a way to run sync for a given group of contacts, it's a way to specify one of the configured subscription sync groups.)

### `max_time` parameter (integer) number of seconds

- 300 means 5 mins (and is the default if the parameter is not specified)
- 0 means unlimited

With a `max_time` set, the process will continue as normal but after each bit of work it will check to see if it's gone over this time, and it will stop there if it has.  e.g. to do at least 2 minutes' work on a sync you could run with `max_time = 120`

### `since` parameter (string)

- Missing: each audience is synced since the last sync was run.
- `2019-12-11`: sync since 11 December 2019
- `ever`: sync all contacts regardless when they were changed.

!!! note
    Use `ever` don't use some ages-ago date instead because it causes Mailchimp's API to lock up. See [Mailchimp Issues](/discussion/mailchimp-issues.md)

### `stop_on` parameter (String)

Developer use only: stops the sync processing before processing the given stage. The stages (in order) are:

- `readyToFetch`
- `readyToFixContactIds`
- `readyToCreateNewContactsFromMailchimp`
- `readyToCleanUpDuplicates`
- `readyToAddCiviOnly`
- `readyToCheckForGroupChanges`
- `readyToReconcileQueue`
- `readyToSubmitUpdates`

Nb. this leaves the sync in a stopped state; you can continue it later (remember to stop any scheduled calls if doing this!). It can be useful to run a sync and stop before `readyToSubmitUpdates` - as this way CiviCRM will be updated but it will stop before sending any changes to Mailchimp; those updates will sit in the `civicrm_mailchimpsync_update` table though, so you will need to delete them with SQL, or (probably better) use Mailchimpsync.abortsync

## Mailchimpsync.abortsync

This is for emergency use only!

### `group_id` (int)

The group ID of the subscription sync group for which to cancel a sync. This will

- Request that Mailchimp deletes any batches that have not been completed. N.b. when this happens there is no way to find out which records Mailchimp had processed already.

- Record a 'fail' status in the cache table and set the update records to completed with error.

- Update our batch record(s) to 'aborted'

- Release processing locks for that group.


## Mailchimpsync.fetchaccountinfo

### `api_key` (string) parameter

Fetch account information from Mailchimp for the given API key. This has to do quite a lot of separate requests due to the (unfortunate) design of the API, so it's quite slow.

It's used by the Config screen to check the API key, webhooks etc.


## Mailchimpsync.getstatus

Calculate all sorts of stats for each sync connection from our database tables, and optionally fetch status of batches we're waiting on at Mailchimp.

### `batches` (boolean) parameter

If given as 1 then it will fetch batches info from Mailchimp.


## Mailchimpsync.updateconfig

This is used by the Config screen to submit changes to the config.

### `config` (string) JSON encoded data

N.b. this is not free of side effects; Various checks and data changes go on based on the config updates. e.g. if you remove a sync connection (or an account) it will delete records that related to that from this extension's tables.

## Mailchimpsync.updatewebhook

This is used by the Config screen to change webhooks at Mailchimp. There are two types: Audience Webhooks and Batch Webhooks. There is only one batch webhook per account; but audiences have one each. Mailchimp supports there being several webhooks in each case (but only one will be relevant to us).

### `api_key` (string)

The Mailchimp API key.

### `process` (string)

- `add_batch_webhook`
- `delete_batch_webhook`
- `add_webhook`
- `delete_webhook'

### `id` (string)

Used for the delete processes only. The Mailchimp webhook ID to delete.
