<?php
/**
 * This is a mocked version of the Mailchimp API.
 *
 * This 'api' is used in the phpunit tests so that we don't have to talk to a
 * real live Mailchimp account which will be slow and have real world side
 * effects.
 *
 * This mock aims to implement the same behaviour as the real API, based on the
 * documentation.
 */

class CRM_Mailchimpsync_MailchimpApiMock extends CRM_Mailchimpsync_MailchimpApiBase implements CRM_Mailchimpsync_MailchimpApiInterface
{
  public $batches = [];
  /** @var array */
  public $mock_mailchimp_data;
  /** @var array keyed by batch_id */
  public $mock_mailchimp_batch_status;
  /** @var array keyed by URL */
  public $mock_mailchimp_batch_results;
  /**
   * Set mock list/member data.
   *
   * {
   *    <list_id>: [
   *      { fname, lname, email, status, last_changed },
   *      ...
   *    ],
   *    ...
   * }
   *
   * @return CRM_Mailchimpsync_MailchimpApiMock (this)
   */
  public function setMockMailchimpData($data) {
    $this->mock_mailchimp_data = $data;
    return $this;
  }

  /**
   * Set mock batch status data.
   *
   * @return CRM_Mailchimpsync_MailchimpApiMock (this)
   */
  public function setMockMailchimpBatchStatus($batch_id, $data) {
    $this->mock_mailchimp_batch_status[$batch_id] = $data;
    return $this;
  }

  /**
   * Set mock batch results data.
   *
   * @return CRM_Mailchimpsync_MailchimpApiMock (this)
   */
  public function setMockMailchimpBatchResults($url, $data) {

    // We need to create a tar file(!)
    $data = json_encode($data);
    $len = strlen($data);
    $filename = "doesnotmatter.json";
    $blocks = ceil($len/512) + 2;
    $tar = str_repeat("\x00", $blocks*512);
    $tar = substr_replace($tar, $filename, 0, strlen($filename));
    $tar[156] = '1';
    $len_oct = str_pad(decoct($len), 12, '0', STR_PAD_LEFT);
    $tar = substr_replace($tar, $len_oct, 124, 12);
    $tar = substr_replace($tar, $data, 512, $len);

    $this->mock_mailchimp_batch_results[$url] = $tar;
    return $this;
  }

  /**
   * Mock HTTP request to Mailchimp API.
   *
   * Returns array.
    $data = $api->get("batches/$this->mailchimp_batch_id");
   */
  protected function request(string $method, string $path, array $options=[]) {
    $query = $options['query'] ?? [];
    $body = $options['body'] ?? [];
    if ($method === 'GET') {
      if (preg_match(';lists/([^/]+)/members$;', $path, $matches)) {
        return $this->mockGetListMembers($matches[1], $query);
      }
      elseif (preg_match(';batches/([a-z0-9]{10})$;', $path, $matches)) {
        return $this->mockGetBatchStatus($matches[1]);
      }
    }
    elseif ($method === 'PUT') {
      if ($path === 'batches') {
        return $this->mockPutBatches($body);
      }
    }
    throw new Exception("Code not written to mock the request: ". json_encode(
      [
        'method' => $method,
        'path'   => $path,
        'query'  => $query,
        'data'   => $body,
      ], JSON_PRETTY_PRINT));
  }
  public function mockGetListMembers($list_id, $query) {

    if (!isset($this->mock_mailchimp_data[$list_id])) {
      throw new CRM_Mailchimpsync_RequestErrorException('mock: list ID not found', 404);
    }

    $body = [
      'list_id' => $list_id,
      'total_items' => 0,
      'members' => [],
      '_links' => [],
    ];
    foreach ($this->mock_mailchimp_data[$list_id]['members'] as $member) {
      // @todo apply queries
      $body['members'][] = [
        'id'            => $this->getMailchimpMemberIdFromEmail($member['email']),
        'email_address' => $member['email'],
        'status'        => $member['status'],
        'merge_fields'  => [
          'fname' => $member['fname'],
          'lname' => $member['lname'],
        ],
        'last_changed' => $member['last_changed'],
        //'interests' => [],
      ];
    }

    // The live API returns a decoded Array from the JSON body received from the API.
    return $body;
  }
  /**
   * @param string $batch_id 10 byte hex ID.
   */
  public function mockGetBatchStatus($batch_id) {
    if (isset($this->mock_mailchimp_batch_status[$batch_id])) {
      return $this->mock_mailchimp_batch_status[$batch_id];
    }
    throw new InvalidArgumentException("Batch $batch_id has no mock");
  }
  /**
   */
  public function mockPutBatches($data) {
    $id = "batch_" . count($this->batches);
    $this->batches[$id] = $data;
    return [
      'id' => $id,
      // ...
    ];
  }
  /**
   * Download the resource URL to an uncompressed tar file.
   *
   * @param string $url
   *
   * @return string filename to temporary file.
   */
  public function downloadBatchResponse($url) {
    if (isset($this->mock_mailchimp_batch_results[$url])) {
      $filename = CRM_Utils_File::tempnam('mailchimsync-batch-response-');
      file_put_contents($filename, $this->mock_mailchimp_batch_results[$url]);
      return $filename;
    }
    throw new InvalidArgumentException("Mock has no data for $url");
  }
}
