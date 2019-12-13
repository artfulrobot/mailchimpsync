This documents some optimisation notes.

## populateMissingContactIds

When we have an email from mailchimp but don't know the contact id, we
need to find one. There are many stages to this process, we start at the
narrowest and then do broader searches, to ensure we get the highest
quality match available.

For an initial sync the query handles the whole list of email addresses,
but for subsequent syncs it's only going to be handling a few.

The challenges were/are:

- There's no efficient way to find emails that belong to deleted contacts
  as of CiviCRM 5.20

- You can't use a temporary table twice in one query (you can using WITH
  in recent MySQL/MariaDB versions but I did not want to rely on that)

### The first stage

The first match is to find an undeleted email address that only belongs to
one contact. It's fine if a contact has the same email address listed
several times (as is often the case) but we don't want to use it if it
belongs to two different (undeleted) contacts. It's fine if it belongs to
Wilma and Fred, but Fred has been deleted, for example.

Here's my original single SQL query. With 50k contacts to match it took
around 8s and with just 40 to update it took 4s still!

```sql
UPDATE civicrm_mailchimpsync_cache mc
  INNER JOIN (
    SELECT e.email, MIN(e.contact_id) contact_id
      FROM civicrm_email e
      INNER JOIN civicrm_contact c1 ON e.contact_id = c1.id AND NOT c1.is_deleted
    GROUP BY e.email
    HAVING COUNT(DISTINCT e.contact_id) = 1
  ) c ON c.email = mc.mailchimp_email
  SET mc.civicrm_contact_id = c.contact_id
  WHERE mc.civicrm_contact_id IS NULL
        AND c.contact_id IS NOT NULL
        AND mc.mailchimp_list_id = %1
```

After a lot of work, I found a way that reduces the processing to ~4s for
50k match-ups, and 0.5s for 1k match-ups. It's a beast:

```sql
-- Using a temporary table here is key - without this the query spirals into exeedingly long times.
-- Interestingly, it is faster to declar the primary key at the start than it is to add it later.
CREATE TEMPORARY TABLE mcs_emails_needing_matches (email VARCHAR(255) PRIMARY KEY)
  SELECT mailchimp_email email FROM civicrm_mailchimpsync_cache mc
  WHERE mc.civicrm_contact_id IS NULL AND mc.mailchimp_list_id = %1 AND mailchimp_email IS NOT NULL
;

-- We need a table of emails from Civi that aren't deleted.
-- Nb. the oder of the EXISTS clauses makes a difference here; we check first
-- for needing matches because this will pretty much always be fewer than the
-- number of contacts in our database.
-- NOT EXISTS (is_deleted) is marginally faster than EXISTS (is_deleted = 0),
-- presumably because there are fewere deleted contacts to search through.
CREATE TEMPORARY TABLE mcs_undeleted_emails (
  contact_id INT(10) UNSIGNED,
  email VARCHAR(255),
  KEY (email, contact_id)
)
SELECT contact_id, email
FROM civicrm_email e
WHERE e.email IS NOT NULL
AND EXISTS (SELECT 1 FROM mcs_emails_needing_matches me WHERE me.email = e.email )
AND NOT EXISTS (SELECT 1 FROM civicrm_contact WHERE id=contact_id AND is_deleted = 1)
;

-- Create table of emails that only belong to one contact.
CREATE TEMPORARY TABLE mcs_emails3 (
  email VARCHAR(255) PRIMARY KEY,
  contact_id INT(10) UNSIGNED
)
SELECT email, MIN(contact_id) contact_id
FROM mcs_undeleted_emails ue
GROUP BY email
HAVING COUNT(DISTINCT contact_id) = 1;

-- Now update our main table where there's only one.
UPDATE civicrm_mailchimpsync_cache mc
  INNER JOIN mcs_emails3 ue1 ON ue1.email = mc.mailchimp_email
  SET mc.civicrm_contact_id = ue1.contact_id
  WHERE mc.civicrm_contact_id IS NULL AND mc.mailchimp_list_id = %1 ;
```


