This documents some optimisation notes.

## populateMissingContactIds

This original SQL is slow (4s on my client's db):

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

It's slow even when there's only a couple of dozen records to update.

Instead we can create a temporary table really fast:
```sql
CREATE TEMPORARY TABLE mcs1 (
    contact_id INT(10) UNSIGNED NOT NULL DEFAULT '0',
    email VARCHAR(255) PRIMARY KEY
  )
  SELECT email, MIN(contact_id) contact_id
  FROM civicrm_email e INNER JOIN civicrm_contact c ON e.contact_id = c.id AND c.is_deleted = 0
  WHERE e.email IS NOT NULL AND e.email IN (
    SELECT mailchimp_email
    FROM civicrm_mailchimpsync_cache
    WHERE mailchimp_list_id = %1 AND civicrm_contact_id IS NULL AND mailchimp_email IS NOT NULL)
  GROUP BY email
  HAVING  COUNT(DISTINCT contact_id) = 1
```
and update from that:

```sql
UPDATE civicrm_mailchimpsync_cache mc
  INNER JOIN mcs1 ON mcs1.email = mc.mailchimp_email
SET mc.civicrm_contact_id = mcs1.contact_id
WHERE mc.civicrm_contact_id IS NULL
  AND mc.mailchimp_email IS NOT NULL
  AND mc.mailchimp_list_id = %1";
```

and dropping a table using `DROP TEMPORARY` does not cause the transaction to commit.
