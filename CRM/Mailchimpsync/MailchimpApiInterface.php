<?php

interface CRM_Mailchimpsync_MailchimpApiInterface {

  /**
   * Create a mocked API.
   *
   * @param string $api_key
   */
  public function __construct($api_key);
}
