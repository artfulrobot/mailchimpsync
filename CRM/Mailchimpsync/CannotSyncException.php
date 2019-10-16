<?php
/**
 * @class
 * Exception used when a contact cannot be made in sync between the two systems.
 *
 * Examples:
 * - Contact is subscribed at CiviCRM but in a 'cleaned' status at Mailchimp.
 * - Contact is subscribed at CiviCRM but has no valid email.
 */
class CRM_Mailchimpsync_CannotSyncException extends Exception {}
