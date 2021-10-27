<?php
require('config.php');
require('src/controller.php');
$ver = '0.0.2'; $base = 'https://'.$config['base'];
date_default_timezone_set($config['nodeTimezone']);

if ($config['nodeDebugging']) {
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