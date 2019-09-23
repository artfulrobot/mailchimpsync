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
   * Merge subscriber data form Mailchimp into our table.
   *
   * @param array $params with keys:
   * - list_id  Mailchimp list ID.
   * - since    Only load things changed since this date. (optional)
   */
  public function mergeMailchimpData(array $params) {
    if (!isset($params['list_id'])) {
      throw new \InvalidArgumentException('mergeMailchimpData requires list_id');
    }

    $query = [
      'count' => static::MAX_MEMBERS_COUNT,
      'offset' => 0,
    ];
    do {
      $response = $this->get("lists/$params[list_id]/members", $query);

      // Fetch (filtered) data from our mock_mailchimp_data array.
      // Insert it into our cache table.
      foreach ($response['members'] ?? [] as $member) {
        $this->mergeMailchimpMember($params['list_id'], $member);
      }

      // Prepare to load next page.
      $query['offset'] += static::MAX_MEMBERS_COUNT;

    } while ($response['total_items'] > $query['offset']);

  }
  /**
   * Copy data from mailchimp into our table.
   *
   * @param string $list_id
   * @param object $member
   *
   * @return CRM_Mailchimpsync_BAO_MailchimpsyncCache
   */
  public function mergeMailchimpMember($list_id, $member) {
    // Find ID in table.
    $bao = new CRM_Mailchimpsync_BAO_MailchimpsyncCache();
    $bao->mailchimp_member_id = $member['id'];
    $bao->mailchimp_list_id = $list_id;
    if (!$bao->find(1)) {
      // New person.
      $bao->mailchimp_email = $member['email_address'];
    }
    $bao->mailchimp_status = $member['status'];
    $bao->mailchimp_updated = $member['last_changed'];

    // Create JSON data from Mailchimp. @todo
    $data = [];
    $bao->mailchimp_data = json_encode($data);

    // Update
    $bao->save();

    return $bao;
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
