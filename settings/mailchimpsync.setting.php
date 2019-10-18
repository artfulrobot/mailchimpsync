<?php
return [
  'mailchimpsync_config' => [
    'name'        => 'mailchimpsync_config',
    'title'       => ts('Mailchimp Sync Configuration'),
    'description' => ts('JSON encoded settings storing details of sync connections.'),
    'group_name'  => 'domain',
    'type'        => 'String',
    'default'     => FALSE,
    'add'         => '5.10',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ]
];
