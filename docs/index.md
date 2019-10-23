# Mailchimp Sync

This extension keeps Mailchimp Audience(s) in sync with CiviCRM mailing groups.

## Top level summary

- A Mailchimp Audience (previously/sometimes aka "List") can be linked with
  a CiviCRM Mailing Group for the purposes of mapping subscriptions: Being
  "subcribed" to the Audience in Mailchimp menas being "Added" to the group in
  CiviCRM. This is a 2-way sync: add/remove at Mailchimp or CiviCRM should make
  the same change at the other.

- Within an Audience you can set links between Mailchimp Interests and other
  CiviCRM Mailing Groups. This is also a 2-way sync.

- You can develop your own extensions to feed Mailchimp with other information
  from CiviCRM. This should be considered a 1-way sync; a way to get data from
  CiviCRM into Mailchimp, e.g. for purposes of create a segment. You could
  implement things like Membership end date, date of last donation, postal
  address.

- You can use as many Mailchimp Accounts, Audiences and Interests as you need.

## A complex process

While these goals sound simple, both services (Mailchimp and CiviCRM) work in
quite different ways and there can be a lot of data. These facts make the
process difficult and give birth to a host of complex situations that can
prevent things being in sync.

Certain decisions have been made, based on experience, to make things as
efficient and reliable as possible, but there are still limitations.

There's a status screen  at **Mailings » Mailchimp Sync Status** that helps you
understand how in-sync your groups are at any time.

## Configuration

To set up sync connections between Mailchimp audiences/lists and CiviCRM
groups, please visit **Administer » System Status » Configure Mailchimp Sync**

After setting up the links between your CiviCRM subscription groups and
Mailchimp Lists/Audiences you will need to start an initial sync of all
contacts.

### Fetch and reconcile

The `Mailchimpsync.Fetchandreconcile` API job needs running regularly.

This should be called only as often as you want to be processing. If
you call it all the time it will run constantly and eat your server
resources for little gain. Hourly is probably reasonable.

By default this API action will try to process all lists within 5 minutes.
You can specify the `max_time` (in seconds) to suit your environment and
a specific `group_id` if you want to run it for a single subscription
group.

Behind the scenes, this API calls `Audience::fetchAndReconcile` for each
list/audience which deals with running everything in order.

