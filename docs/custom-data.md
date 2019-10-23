# Push custom data to Mailchimp

You can write your own extensions that add data to be sent to Mailchimp.
Note that this should be considered a one-way sync, i.e. CiviCRM data sent
to Mailchimp.

## Example

Let's say you want the membership end date available as a Mailchimp 'merge
field' and your extension is called `myext`.

```php
/**
 * Registeres our hook
 *
 * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
 */
function myext_civicrm_container($container) {
  $container->findDefinition('dispatcher')
    ->addMethodCall('addListener',
    ['hook_mailchimpsync_reconcile_item', 'myext_reconcile_item']);
}

/**
 * Implements hook_mailchimpsync_reconcile_item
 *
 * @param Civi\Core\Event\GenericHookEvent
 */
function myext_reconcile_item($event) {
  // CRM_Mailchimpsync_Audience
  $audience = $event->audience;
  // CRM_Mailchimpsync_BAO_MailchimpsyncCache
  $cache_entry = $event->cache_entry;
  // Array
  $mailchimp_updates = &$event->mailchimp_updates;

  // Fetch the membership.
  $membership = civicrm_api3('Membership', 'get', [
    'contact_id'  => $cache_entry->civicrm_contact_id,
    'active_only' => 1,
    'sequential'  => 1,
  ]);

  // Provide update.
  $mailchimp_updates['merge_fields']['MEMBEREND'] =
    ($membership['values'][0]['end_date'] ?? 'N/A');
}
```

!!! note
    this example is over-simplified. Ideally you would check the update is
    necessary since adding any update will slow down a sync process.


