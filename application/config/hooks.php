<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$hook['pre_system'] = array(
    'class'    => 'Cors',
    'function' => 'handle',
    'filename' => 'Cors.php',
    'filepath' => 'hooks',
);
