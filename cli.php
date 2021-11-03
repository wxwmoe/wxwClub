#!/usr/bin/env php
<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

require('config.php');
require('src/worker.php');
date_default_timezone_set($config['nodeTimezone']);
$ver = '0.0.3'; $base = 'https://'.$config['base']; $stop = false;

declare(ticks = 1);
pcntl_signal(SIGINT, 'shutdown');
pcntl_signal(SIGTERM, 'shutdown');

function shutdown() {
    global $stop; $stop = true;
    echo 'Stopping, please wait ...',"\n";
};

if ($config['nodeDebugging']) {
    if (!is_dir('error_logs')) mkdir('error_logs');
    ini_set('error_log', 'error_logs/'.date('Y-m-d_H:i:s').'_error.log');
}

try {
    $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'],
        $config['mysql']['username'], $config['mysql']['password'], [PDO::ATTR_PERSISTENT => true]);
    if (isset($argv[1])) switch ($argv[1]) {
        case 'worker': echo 'Start running worker ...',"\n"; while (!$stop) worker(); echo 'Worker stopped',"\n"; break;
        default: echo 'Unknown parameters',"\n"; break;
    }
} catch (PDOException $e) {
    exit('Error: ' . $e->getMessage());
}