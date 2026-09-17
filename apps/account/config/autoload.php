<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$autoload['packages']  = array(SHAREDPATH);
$autoload['libraries'] = array('database', 'Api_response', 'Validator', 'Redisx', 'Ratelimit');
$autoload['drivers']   = array();
$autoload['helper']    = array();
$autoload['config']    = array('account');
$autoload['language']  = array();
$autoload['model']     = array();
