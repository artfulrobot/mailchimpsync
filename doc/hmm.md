# Plans

Q: which records to process, and how?

Q: are all eventualities covered?

- contact added at MC                               changed
- contact unsubscribed at MC                        changed
- contact cleaned at MC                             changed
- contact archived at MC                            changed
- contact 'deleted' at MC                           ?
- contact interests changed at MC                   changed
- contact other data changed at MC                  changed
- contact added at CiviCRM                          date
- contact removed at CiviCRM                        date
- contact-group deleted at CiviCRM                  ?
- contact deleted at CiviCRM                        ?
- contact fully deleted at CiviCRM                  ?
- contact added to interest group at CiviCRM        ?
- contact removed to interest group at CiviCRM      ?
- contact interest group deletion at CiviCRM        ?
- contact other data changed at CiviCRM             ?


We can access our data live (ish).

By fetching since updated from Mailchimp we can update our cache table with most
of the info.
The things left unchecked are CiviCRM's updates.

Idea: have a "needs update" flag on the cache table; set on MC import; run
various other from civi to also set it.

- Copy the 'needs update on Mailchimp' group to it.
- Scan group subscription history for recent dates; add those.
- Scan contact created dates?
- Various hooks set this?
- option to force update of all contacts.

Process is to loop all needs_update records.

Each one may result in changes at civi or Mailchimp, maybe both?
Mailchimp changes should be batched; CiviCRM changes should be immediate.

Sync status:
- todo
- active
- ok
- mailchimp_updates_json
- batchID (once submitted)

Mailchimp batches
- batchID
- mailchimp batch code/link
- started
- completed

So the only process that can't be interrupted is the initial data fetch; we need
to know that that is complete. That process is:

1. fetch (recent) Mailchimp data for list (slow) - could be broken into jobs
2. removeInvalidContactIds
3. populateMissingContactIds (slowish)
4. createNewContactsFromMailchimp
5. addCiviOnly

There should be a lock on this audience during this process. At the end of the
process, the lock is removed. If any step fails, do not proceed to
reconciliation.


Loop all `todo`, fill up batch queue. If this is interrupted, that's fine, it
will restart.

Second process (or triggered during loop after N records added to batch queue)
creates batches and submits them.

Batches are polled; when one is complete sync status is set to OK for all its
records. If any are not found when checking Mailchimp but still exist in the
batches table, set their records to todo.

## Sync called on an audience (possibly with 'recent' set)

1. Lock audience to prevent another process starting a sync or processing
   records.

2. Gather data

3. If all gatherings completed, reconcile. This leads to immediate changes in
   CiviCRM and a list of changes for Mailchimp. Release lock. If gatherings did
   not complete OK, do NOT reconcile, just release lock. Somehow alert users to
   error.

4. (cron) Look for work in queue; create Mailchimp batches.

5. (cron) Check mailchimp batches; remove from queue when done.

## Locks and config

Use config?

```
config.lists.<listID>.lock = {
    queue_id, // 'mailchimpsync_' + <listID>
    since: <datetime>, // If recent sync
    jobs: [
        mailchimpFetch: { started: <datetime>, completed: null|<datetime>, stats }
        removeInvalidContactIds: ...
        populateMissingContactIds: ...
        createNewContactsFromMailchimp: ...
        addCiviOnly: ...
        reconcile,
    ],
}
```

Each list has it's own settings:
- subscriptionGroupId
- fetch:
    - lastFetchedAt: null|datetime
    - status: live|wait
    - startedAt: null|datetime
    - completedAt: null|datetime
    - updatedAt: null|datetime
    - log: []

## Q. how is a process restartable by cron?


Feels bit worrying putting fast changing data in a JSON structure, but maybe
that's OK.

## Edge cases

If you delete a contact, or delete it's subscription record (how is that
handled?) we'll have something at MC and nothing at Civi and this could lead to
reinstating a record.

This could be handled by a hook on delete that notes in the cache table that the
contact is `known_deleted` and therefore should be removed from Mailchimp lists
during the next sync.

## Thinking through rows in the cache table.

```
m_email    c_id  m_status        m_changed    (c_status) sync
---------- ----- --------------- ------------ ---------- -------
eg1        1     subscribed      today        A/R/-      todo
eg2        2     unsubscribed    yesterday    A/R/-      todo
eg3        3     cleaned         today        A/R/-      todo
eg4        4     pending         today        A/R/-      todo
eg5        5     transactional   today        A/R/-      todo
eg6        6     archived        today        A/R/-      todo
eg7        7     -               -            Added      todo
```

Queue runner uses `mcs_locks` setting or such to ensure that 2 runners don't
attack the same audience at once.

Queue runner (not the CiviCRM Queue) processes each row. The result of the
processing is:

- possible updates to CiviCRM; sync → ok
- possible updates to Mailchimp; sync → pending

Queue runner may have `max_processing_time` and exit after that time, leaving
some in `todo` state. We need a way to detect a crash, though.

Mailchimp updates.

-  table needs to know if it's been submitted/done.

- `mailchimpsync_updates` table. This table should use COMPRESSED
    - `ID`
    - `mailchimpsync_cache_id` FK (suppose it could be NULL)
    - `data` TEXT json
    - `batch_id` FK to Mailchimp batches
    - `completed` boolean

- Mailchimp batches table
    - `ID`
    - `batch_id`
    - `complete` (Stats)

- separate process (singleton) grabs 1000 of these that don't have `batch_id` and submits a
  batch and updates the `batch_id`.

- separate process polls MC for all live batches from our batches table. If any
  have completed, then update all the rows in mailchimp updates for that batch,
  setting completed=1. And update the batches row. And do a general SQL call on
  the cache table updating the status to `ok` if it was `pending` and there are
  no incomplete updates in the updates table.

- UI can show all operations:

   - For each audience it can count (pending/todo) and report this to show how
     out of sync it is(!)
   - It can also report on Mailchimp batch updates.
   - This page could refresh by ajax.


