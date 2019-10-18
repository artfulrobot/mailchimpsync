CREATE TABLE IF NOT EXISTS civicrm_mailchimpsync_status (
  list_id VARCHAR(32) NOT NULL PRIMARY KEY,
  data MEDIUMBLOB NOT NULL
);
