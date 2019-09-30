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
   * Mock HTTP request to Mailchimp API.
   *
   * Returns array.
   */
  protected function request(string $method, string $path, array $options=[]) {
    $query = $options['query'] ?? [];
    $body = $options['body'] ?? [];
    if ($method === 'GET') {
      if (preg_match(';lists/([^/]+)/members$;', $path, $matches)) {
        return $this->mockGetListMembers($matches[1], $query);
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
   */
  public function mockPutBatches($data) {
    $id = "batch_" . count($this->batches);
    $this->batches[$id] = $data;
    return [
      'id' => $id,
      // ...
    ];
  }
}
