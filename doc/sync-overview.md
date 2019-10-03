# Sync overview

The sync process is complex and is made up of several asynchronous processes.

1. **Fetch**: When a sync is started a process fetches information from Mailchimp
   and CiviCRM and updates a local subscribers cache table. The result of this
   operation is that we have a list of every contact on a list/audience and in
   CiviCRM, and we know the subscription status at each end.

2. **Reconciliation**: compares it. Updates to CiviCRM are made immediately.
   Updates to Mailchimp are queued.

2. **Batcher**: A cron-driven process batches up queued mailchimp updates 1,000
   at a time and submits them for batch processing at Mailchimp.

3. **Batch result handler**: A Mailchimp Batch Update Webhook causes the result
   of a batch to be fetched (from Mailchimp) and inspected. Unsusccessful
   updates that were due to us trying to subscribe someone who previously
   unsubscribed result in a new update job to set their status to 'pending'.
   Other failures are just logged.

![Flowchart diagram][./sync.svg]

## Configuration

After setting up the links between your CiviCRM subscription groups and
Mailchimp Lists/Audiences you will need to start an initial sync of all
contacts.

`Audience::fetchAndReconcile` deals with running everything in order. Note
that this should be called only as often as you want to be processing. If
you call it all the time it will run constantly and eat your server
resources for little gain. Hourly is probably reasonable.

@todo add scheduled job wrapper for this.

