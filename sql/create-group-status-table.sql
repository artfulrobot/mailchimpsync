DROP TABLE IF EXISTS agroup;
CREATE TABLE agroup SELECT SQL_NO_CACHE group_id, contact_id, status, date
FROM civicrm_subscription_history h1
WHERE group_id IS NOT NULL AND contact_id IS NOT NULL
AND NOT EXISTS (
  SELECT id FROM civicrm_subscription_history h2
  WHERE h2.group_id = h1.group_id
  AND h2.contact_id = h1.contact_id
  AND h2.id > h1.id  );
ALTER TABLE agroup ADD PRIMARY KEY (group_id, contact_id);
