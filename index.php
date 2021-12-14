<?php
require('config.php');
require('src/controller.php');
define('APP_ROOT', dirname(__FILE__));
date_default_timezone_set($config['nodeTimezone']);
$ver = '0.0.3'; $base = 'https://'.$config['base'];
$public_streams = 'https://www.w3.org/ns/activitystreams#Public';

if ($config['nodeDebugging']) {
    if (!is_dir('logs')) mkdir('logs');
    if (!is_dir('logs/curl')) mkdir('logs/curl');
    if (!is_dir('logs/error')) mkdir('logs/error');
    if (!is_dir('logs/inbox')) mkdir('logs/inbox');
    if (!is_dir('logs/outbox')) mkdir('logs/outbox');
    if (!is_dir('logs/webfinger')) mkdir('logs/webfinger');
    ini_set('error_log', 'logs/error/'.date('Y-m-d_H:i:s').'_error.log');
}

try {
    $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'],
        $config['mysql']['username'], $config['mysql']['password'], [PDO::ATTR_PERSISTENT => true]);
    controller();
} catch (PDOException $e) {
    http_response_code(500);
    exit('Error: '.$e->getMessage());
}