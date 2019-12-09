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
  /**
   * Array of array of calls to methods that don't need to return anything, so we can check they were called.
   */
  public $calls = [];
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
   * This has to create sometihng resembling a tar file.
   * Nb. no standards are implemented, we only provide the data necessary for our parser.
   *
   * The data array is an array keyed by filenames. Filenames don't matter but
   * mailchimp's are like 1234fed.json. The values are array structures that
   * contain the contents of the file. This has an outer key of 'data' and within
   * it is another array of arrays for each response.
   *
   * @param string $url
   * @param array $data
   *
   * @return CRM_Mailchimpsync_MailchimpApiMock (this)
   */
  public function setMockMailchimpBatchResults($url, $data) {

    // We need to create an awkward tar file(!) like Mailchimp does. Awkward
    // because it cannot be processed by PHP's inbuilt tar opening functions
    // thanks to paths that start ./

    // Create initial directory chunk.
    $tar = str_repeat("\x00", 512);
    $tar = substr_replace($tar, './', 0, 2);
    $tar[156] = '5'; // directory
    $len_oct = '000000000000';
    $tar = substr_replace($tar, $len_oct, 124, 12);

    // Add files.
    foreach ($data as $filename => $content) {
      $tar .= $this->createMailchimpTarChunk("./$filename", $content);
    }
    // Add empty chunk that means end of tar file.
    $tar .= str_repeat("\x00", 512);

    $this->mock_mailchimp_batch_results[$url] = $tar;
    return $this;
  }
  /**
   * This creates a file as it might appear in a mailchimp tar file.
   *
   * Nb. no standards are implemented, we only provide the data necessary for our parser.
   */
  protected function createMailchimpTarChunk($filename, $data) {
    $data = json_encode($data);
    $len = strlen($data);
    $blocks = ceil($len/512) + 1;
    $tar = str_repeat("\x00", $blocks*512);
    $tar = substr_replace($tar, $filename, 0, strlen($filename));
    $tar[156] = '1';
    $len_oct = str_pad(decoct($len), 12, '0', STR_PAD_LEFT);
    $tar = substr_replace($tar, $len_oct, 124, 12);
    $tar = substr_replace($tar, $data, 512, $len);
    return $tar;
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
      if ($path === '') {
        return [
          "account_name" => "Mock Account",
          "email"        => "mailchimp@example.com",
          "first_name"   => "Wilma",
          "last_name"    => "Test",
          "username"     => "wilma",
        ];
      }
      elseif ($path === 'lists') {
        return [
          'lists' => [[
              'id' => 'list_1',
              'name' => 'Mock audience/list',
              'stats' => [
                'member_count' => 2,
                'unsubscribed_count' => 1,
                'cleaned_count' => 1,
                'click_rate' => 0,
                'open_rate' => 80,
              ]
          ]]];
      }
      elseif (preg_match(';lists/([^/]+)/members$;', $path, $matches)) {
        return $this->mockGetListMembers($matches[1], $query);
      }
      elseif (preg_match(';lists/([^/]+)/members/([^/]+)$;', $path, $matches)) {
        return $this->mockGetListMember($matches[1], $matches[2], $query);
      }
      elseif (preg_match(';lists/([^/]+)/interest-categories$;', $path, $matches)) {
        return $this->mockGetListInterestCategories($matches[1], $query);
      }
      elseif (preg_match(';lists/([^/]+)/interest-categories/([^/]+)/interests$;', $path, $matches)) {
        return $this->mockGetListInterests($matches[1], $matches[2], $query);
      }
      elseif (preg_match(';lists/([^/]+)/webhooks$;', $path, $matches)) {
        return $this->mockGetListWebhooks($matches[1], $query);
      }
      elseif ($path === 'batch-webhooks') {
        return $this->mockGetBatchWebhooks();
      }
      elseif ($path === 'batches') {
        return $this->mockGetBatches();
      }
      elseif (preg_match(';batches/([a-z0-9]{10})$;', $path, $matches)) {
        return $this->mockGetBatchStatus($matches[1]);
      }
    }
    elseif ($method === 'POST') {
      if ($path === 'batches') {
        return $this->mockPostBatches($body);
      }
      if (preg_match(';batch-webhooks|lists/[a-z0-9_]+/webhooks;', $path)) {
        return $this->logCall($method, $path, $options);
      }
    }
    elseif ($method === 'DELETE') {
      if (preg_match(';batches/([a-z0-9]{10})$;', $path, $matches)) {
        return $this->mockDeleteBatches($matches[1]);
      }
      if (preg_match(';batch-webhooks/[a-z0-9]+|lists/[a-z0-9_]+/webhooks;', $path)) {
        return $this->logCall($method, $path, $options);
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
      'total_items' => count($this->mock_mailchimp_data[$list_id]['members']),
      'members' => [],
      '_links' => [],
    ];
    foreach (array_slice(
        $this->mock_mailchimp_data[$list_id]['members'],
        $query['offset'] ?? 0,
        $this->max_members_to_fetch)
     as $member) {
      // @todo apply queries
      $body['members'][] = [
        'id'            => $this->getMailchimpMemberIdFromEmail($member['email']),
        'email_address' => $member['email'],
        'status'        => $member['status'],
        'merge_fields'  => [
          'FNAME' => $member['fname'],
          'LNAME' => $member['lname'],
        ],
        'last_changed' => $member['last_changed'],
        //'interests' => [],
      ];
    }

    // The live API returns a decoded Array from the JSON body received from the API.
    return $body;
  }
  public function mockGetListMember($list_id, $mailchimp_id, $query) {

    $list_members = $this->mockGetListMembers($list_id, $query);
    foreach ($list_members['members'] as $member) {
      if ($member['id'] === $mailchimp_id) {
        return $member;
      }
    }
    throw new InvalidArgumentException("Requested member not found.");
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
  public function mockGetBatchWebhooks() {
    return ['webhooks' => []];
  }
  /**
   */
  public function mockGetListWebhooks($list_id, $query) {
    return ['webhooks' => []];
  }
  /**
   */
  public function mockGetBatches() {
    return ['batches' => $this->mock_mailchimp_batch_status];
  }
  /**
   */
  public function mockPostBatches($data) {
    $id = "batch_" . count($this->batches);
    $this->batches[$id] = $data;
    return [
      'id' => $id,
      // ...
    ];
  }
  /**
   */
  public function mockDeleteBatches( $mailchimp_batch_id) {
    if (isset($this->mock_mailchimp_batch_status[$mailchimp_batch_id])) {
      unset($this->mock_mailchimp_batch_status[$mailchimp_batch_id]);
      return;
    }
    throw new \InvalidArgumentException("Unknown batch $mailchimp_batch_id");
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
  public function mockGetListInterestCategories($list_id) {
    if (!$list_id === 'list_1') {
      throw new InvalidArgumentException("mock not programmed for list '$list_id'");
    }
    return [
      'categories' => ['cat1cat1' => ['title' => 'Category 1', 'id' => 'cat1cat1']],
    ];
  }
  public function mockGetListInterests($list_id, $category) {
    if (!$list_id === 'list_1') {
      throw new InvalidArgumentException("mock not programmed for list '$list_id'");
    }
    if (!$category === 'cat1cat1') {
      throw new InvalidArgumentException("mock not programmed for category '$category'");
    }
    return [
      'interests' => ['int1int1' => ['name' => 'Interest 1', 'id' => 'int1int1']],
    ];
  }
  /**
   * Just log call.
   */
  public function logCall($method, $path, $options) {
    $this->calls[] = ['method' => $method, 'path' => $path, 'options' => $options];
  }
}
