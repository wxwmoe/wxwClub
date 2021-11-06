<?php
require('config.php');
require('src/controller.php');
define('APP_ROOT', dirname(__FILE__));
date_default_timezone_set($config['nodeTimezone']);
$ver = '0.0.3'; $base = 'https://'.$config['base'];
$public_streams = 'https://www.w3.org/ns/activitystreams#Public';

if ($config['nodeDebugging']) {
    if (!is_dir('curl_logs')) mkdir('curl_logs');
    if (!is_dir('error_logs')) mkdir('error_logs');
    if (!is_dir('inbox_logs')) mkdir('inbox_logs');
    if (!is_dir('outbox_logs')) mkdir('outbox_logs');
    ini_set('error_log', 'error_logs/'.date('Y-m-d_H:i:s').'_error.log');
}

try {
    $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'],
        $config['mysql']['username'], $config['mysql']['password'], [PDO::ATTR_PERSISTENT => true]);
    controller();
} catch (PDOException $e) {
    exit('Error: ' . $e->getMessage());
}