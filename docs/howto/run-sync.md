Once set up, the sync will be run automatically according to a schedule
(it defaults to hourly). But from time to time you might want to force
a sync run.

## Option 1: Manually execute the scheduled job

!!! warning
    This is not generally a good idea - see below.

1. Go to **Administer » System Settings » Scheduled Jobs**

2. Find the job called **Call Mailchimpsync.Fetchandreconcile**

3. In the **actions** column, click the **more » Execute Now**


### Risks

There's a good chance that the sync will take longer than your web browser will sit and wait for a page to load. What that means for your sync process depends on your hosting environment. For example, with PHP-FPM (nginx users and some Apache users), the sync will likely continue to run, but you'll get a time out error. However with ModPHP (traditional Apache setup) the timeout will kill the sync script mid way through.

Therefore it's probably best not to run the sync in this way.

The other thing to note is that if you reload the scheduled jobs page after executing a job, it will re-execute the job. It's easy for this to happen by accident, e.g. if you close and later reopen your browser. (reported as [Issue 1464](https://lab.civicrm.org/dev/core/issues/1464))

## Option 2: use the API

You can call the **Mailchimpsync.fetchandreconcile** API which has a few useful parameters explained below. Ideally you'd do this from the command line, but you can use the API Exporer or even reconfigure the Scheduled Job (as in option 1) if you know your server well and are careful to pass a suitable `max_time` parameter.

You can set the `max_time` parameter to a number of seconds, e.g. 300 for 5 mins (this is the default), or to 0 for unlimited (suitable for running from the command line). With a `max_time` set, the process will continue as normal but after each bit of work it will check to see if it's gone over this time, and it will stop there if it has.  e.g. to do at least 2 minutes' work on a sync you could run this:

```bash
cv api Mailchimpsync.fetchandreconcile max_time=120
```
Running the same job again will pick up where it left off. The status page will show where it's at.

!!! note
    There are a few other API parameters for this action, see the code/API spec for details, but they're mainly for troubleshooting.


## What's a suitable schedule?

This depends on your usage. The sync begins by fetching records from Mailchimp since the last sync was run, and likewise from CiviCRM. This means that after the initial sync (when it has to load all records) subsequent syncs can be quite efficient. The more sync connections you have set up, the more work there is to do of course. If a sync is running, trying to start a new one will fail.

I would suggest that you montitor the Scheduled Jobs log to see how long the sync is taking (and check whether it's completing within it's `max_time`) - you may get away with running it every 5 minutes even. But if you have a lot of contacts and a lot of lists you're likely to need to think about how this runs.

By default it's set up as a scheduled job, which means it gets run by CiviCRM's cron job. Hopefully you have this set up as a robust system-run cron (if you don't, you *really* should for this sort of work!), but still you might consider disabling the scheduled job in favour of adding a separate cron job that calls the API on whatever schedule you find works. The reason to consider that is that CiviMail is also triggered by the cron/scheduled jobs, so these two potentially long-running jobs will be fighting for time.


