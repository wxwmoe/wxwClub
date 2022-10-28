#!/usr/bin/env php
<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

require('config.php');
require('src/worker.php');
define('APP_ROOT', dirname(__FILE__));
date_default_timezone_set($config['nodeTimezone']);
$ver = '0.0.5'; $base = 'https://'.$config['base'];

declare(ticks = 1);
$cycle = 0; $stop = false;
pcntl_signal(SIGINT, 'shutdown');
pcntl_signal(SIGTERM, 'shutdown');

function shutdown() {
    global $stop; $stop = true;
    echo date('[Y-m-d H:i:s]').' Stopping, please wait ...',"\n";
};

if ($config['nodeDebugging']) {
    if (!is_dir('logs')) mkdir('logs');
    if (!is_dir('logs/curl')) mkdir('logs/curl');
    if (!is_dir('logs/error')) mkdir('logs/error');
    ini_set('error_log', 'logs/error/'.date('Y-m-d_H:i:s').'_error.log');
}

try {
    $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'],
        $config['mysql']['username'], $config['mysql']['password'], [PDO::ATTR_PERSISTENT => true]);
    if (isset($argv[1])) switch ($argv[1]) {
        case 'worker': echo date('[Y-m-d H:i:s]').' Start running worker ...',"\n"; while (!$stop) worker(); echo date('[Y-m-d H:i:s]').' Worker stopped',"\n"; break;
        default: echo 'Unknown parameters',"\n"; break;
    }
} catch (PDOException $e) {
    exit('Error: '.$e->getMessage()."\n");
}