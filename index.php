<?php
require('config.php');
require('src/controller.php');
ini_set('date.timezone', 'Asia/Shanghai');

$ver = '0.0.1'; $base = 'https://'.$config['base'];
if ($config['nodeInboxLogs'] && !is_dir('inbox_logs')) mkdir('inbox_logs');
if ($config['nodeOutboxLogs'] && !is_dir('outbox_logs')) mkdir('outbox_logs');

try {
    $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'],
        $config['mysql']['username'], $config['mysql']['password'], [PDO::ATTR_PERSISTENT => true]);
    controller();
} catch (PDOException $e) {
    exit('Error: ' . $e->getMessage());
}