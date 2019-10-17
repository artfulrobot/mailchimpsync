<?php
/**
 *
 * Base class for Mailchimp API helper.
 *
 */
abstract class CRM_Mailchimpsync_MailchimpApiBase implements CRM_Mailchimpsync_MailchimpApiInterface
{
  /** In Oct 2019, this is the maximum allowed members per request from Mailchimp. */
  const MAX_MEMBERS_COUNT = 1000;

  /**
   * This is used in place of the constant for testing purposes.
   */
  public $max_members_to_fetch = self::MAX_MEMBERS_COUNT;
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
  public static function getMailchimpMemberIdFromEmail($email) {
    $strtolower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
    return md5($strtolower($email));
  }

  /**
   * Make GET request.
   */
  public function get(string $path, array $query=[]) {
    return $this->request('GET', $path, ['query' => $query]);
  }
  /**
   * Make POST request.
   */
  public function post(string $path, $options=[]) {
    return $this->request('POST', $path, $options);
  }
  /**
   * Make PATCH request.
   */
  public function patch(string $path, array $options=[]) {
    return $this->request('PATCH', $path, $options);
  }
  /**
   * Make PUT request.
   */
  public function put(string $path, array $options=[]) {
    return $this->request('PUT', $path, $options);
  }
  /**
   * Make DELETE request.
   */
  public function delete(string $path, array $options=[]) {
    return $this->request('DELETE', $path, $options);
  }
  /**
   * Wrapper around batch submission.
   *
   * @throws UnexpectedValueException if Mailchimp API fails us.
   * @return String batch ID
   */
  public function submitBatch($requests) {
    $response = $this->post('batches', ['body' => [
      'operations' => array_values($requests),
    ]]);
    if (!$response['id']) {
      throw new UnexpectedValueException("Submitting a batch failed to return a batch ID.");
    }
    return $response['id'];
  }
  /**
   * Download the resource URL to an uncompressed tar file.
   *
   * @param string $url
   *
   * @return string filename to temporary file.
   */
  abstract public function downloadBatchResponse($url);

}
