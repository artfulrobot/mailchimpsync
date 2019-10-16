<?php
// This file declares an Angular module which can be autoloaded
// in CiviCRM. See also:
// \https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules/n
return array (
  'js' => 
  array (
    0 => 'ang/mailchimpsync.js',
    1 => 'ang/mailchimpsync/*.js',
    2 => 'ang/mailchimpsync/*/*.js',
  ),
  'css' => 
  array (
    0 => 'ang/mailchimpsync.css',
  ),
  'partials' => 
  array (
    0 => 'ang/mailchimpsync',
  ),
  'requires' => 
  array (
    0 => 'crmUi',
    1 => 'crmUtil',
    2 => 'ngRoute',
  ),
  'settings' => 
  array (
  ),
);
