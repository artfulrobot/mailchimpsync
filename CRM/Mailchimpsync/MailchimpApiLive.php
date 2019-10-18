<?php

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Client;

/**
 * This provides a thin wrapper arount the actual Mailchimp API, connected to an actual account.
 *
 */

class CRM_Mailchimpsync_MailchimpApiLive extends CRM_Mailchimpsync_MailchimpApiBase implements CRM_Mailchimpsync_MailchimpApiInterface
{
  /** @var string */
  protected $api_key;

  /** string URL set in __contruct */
  protected $api_endpoint;

  /**
   * Create live API object.
   *
   * @param string $api_key
   */
  public function __construct($api_key) {
    $this->api_key = $api_key;

    // Set URL based on datacentre identifier at end of api key.
    preg_match('/^.*-([^-]+)$/', $this->api_key, $matches);
    if (empty($matches[1])) {
      throw new InvalidArgumentException("Invalid API key - could not extract datacentre from given API key.");
    }

    $datacenter = $matches[1];
    $this->api_endpoint = "https://$datacenter.api.mailchimp.com/3.0/";
  }

  /**
   * Generic HTTP request to Mailchimp API.
   *
   * This returns the JSON response decoded to an array, or it throws an exception.
   *
   * @throws CRM_Mailchimpsync_RequestErrorException
   * @throws CRM_Mailchimpsync_NetworkErrorException
   *
   * @param string Method, e.g. GET
   * @param string path, e.g. lists/aabbccdd/members
   * @param array options, can contain keys:
   * - query: query params (can be array of key value pairs)
   * - body: body data (typically an array)
   *
   * @return array
   */
  protected function request(string $method, string $path, array $options=[]) {
    $params = [];
    if (!empty($options['query'])) {
      $params['query'] = $options['query'];
    }
    if (!empty($options['body'])) {
      $params['json'] = $options['body'];
    }
    if (!empty($options['auth'])) {
      $params['auth'] = $options['auth'];
    }
    else {
      $params['auth'] = ['CiviCRM', $this->api_key];
    }
    try {
      $response = $this->getGuzzleClient()->request($method, $path, $params);
    }
    catch (GuzzleHttp\Exception\ClientException $e) {
      // 4xx errors
      throw new CRM_Mailchimpsync_RequestErrorException($e->getResponse()->getBody()->getContents(), $e->getCode());
    }
    catch (GuzzleHttp\Exception\TransferException $e) {
      // Everything else
      throw new CRM_Mailchimpsync_NetworkErrorException($e->getMessage(), $e->getCode());
    }

    // was JSON returned, as expected?
    $json_returned = ($response->hasHeader('Content-Type')
      && preg_match(
        '@^application/(problem\+)?json\b@i',
        $response->getHeader('Content-Type')[0]));

    if (!$json_returned) {
      // According to Mailchimp docs it may return non-JSON in event of a
      // timeout.
      throw new CRM_Mailchimpsync_NetworkErrorException('Non-JSON response received from Mailchimp. This suggests a network timeout.');
    }

    // OK, return the JSON.
    return json_decode($response->getBody(), TRUE);
  }

  /**
   * Returns a Guzzle Client with base_uri set to our api endpoint URL.
   *
   * @return Client
   */
  protected function getGuzzleClient() {
    $guzzleClient = new Client([
      'base_uri'    => $this->api_endpoint,
      'http_errors' => TRUE, // Get exceptions from guzzle requests.
    ]);
    return $guzzleClient;
  }
  /**
   * Download the resource URL to an uncompressed tar file.
   */
  public function downloadBatchResponse($url) {

    $filename = CRM_Utils_File::tempnam('mailchimsync-batch-response-');
    try {
      $guzzleClient = new Client([
        'http_errors' => TRUE, // Get exceptions from guzzle requests.
      ]);
      // Note: Mailchimp's response file is gzipped.
      // but this is separate to whether the HTTP response is gzip encoded.

      $guzzleClient->request('get', $url, [
        'decode_content' => 'gzip', // Decode stream if gzip encoded.
        'sink'           => $filename,
      ]);

    }
    catch (Exception $e) {
      if (file_exists($filename)) {
        // Clean up.
        unlink($filename);
      }
      throw $e;
    }

    return $filename;
  }

}

