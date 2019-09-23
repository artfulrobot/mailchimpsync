<?php
/**
 *
 * Base class.
 */
class CRM_Mailchimpsync_MailchimpApiBase implements CRM_Mailchimpsync_MailchimpApiInterface
{
  const MAX_MEMBERS_COUNT = 1000;

  /** @var string */
  protected $api_key;

  /**
   * Create a mocked API.
   *
   * @param string $api_key
   */
  public function __construct($api_key) {
    $this->api_key = $api_key;
  }

  /**
   * Fetch data from CiviCRM for given list_id.
   *
   * @param array $params Must contain key 'list_id'
   */
  public function mergeCiviData(array $params) {

  }
  /**
   * Calculate Mailchimp ID from email.
   *
   * There are a few problems with this, but nothing much we can do.
   *
   * - Mailchimp has a historic bug: early subscribers with mixed case emails
   *   did not use a lowercase email address to generate the ID. It's therefore
   *   difficult to work with these using the API as you can't know the ID!
   *
   * - Emails can actually be case sensitive (rare)
   *
   * - Unicode lowercase functions may or may not be available on our server.
   *
   * @param string $email
   * @return string
   */
  public function getMailchimpMemberIdFromEmail($email) {
    $strtolower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
    return md5($strtolower($email));
  }

  /**
   * Make GET request.
   */
  public function get(string $path, array $query=[]) {
    return $this->request('GET', $path, $query);
  }
}
