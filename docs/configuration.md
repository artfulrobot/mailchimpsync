# Configuration

Two CiviCRM Settings are used:

- `mailchimpsync_config`
- `mailchimpsync_audience_status_XXX`

## Main config settings.

Access this with `CRM_Mailchimpsync::getConfig()`. It is an array
structure as follows:

```
[
  "lists": [
    <mailchimp_list_id>: {
      "apiKey": <mailchimp_or_mock_api_key>,
      "subscriptionGroup": <civicrm_group_id>,
			"originalAudienceName": <string>,
    },
  ],
	"api_keys": {
		<mailchimp_or_mock_api_key>: { "accountName": <string> }
	}
]

```

## Per-audience status settings.

Each audience stores its status in a setting named like
`mailchimpsync_audience_status_<list_id>`

The structure of this is:

```
{
  "lastSyncTime": "2019-10-15 12:34:56",
  "locks": {
    <string lock purpose>: <string lockstatus>,
    ...
  },
  "log": [
    { "time": "2019-10-15 12:34:56", "message": <string> },
    ...
  ],
  "fetch": { "offset": <int> }
}
```

Tasks check they are allowed to run by checking for a particular lock
purpose and lock status to ensure that things run in order. If no lock for
a given purpose exists, it's OK to proceed.

Lock purposes are:

- `fetchAndReconcile` which has possible values:
   - `readyToFetch`
   - `fetch` - a fetch process is in operation (this can take several runs to complete)
   - `readyToCreateNewContactsFromMailchimp`
   - `readyToAddCiviOnly`
   - `readyToCopyCiviGroupStatus`
   - `readyToReconcileQueue`
   - `readyToFixContactIds`

