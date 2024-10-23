#!/usr/bin/env php
<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

require('config.php');
require('src/worker.php');
define('APP_ROOT', dirname(__FILE__));
date_default_timezone_set($config['nodeTimezone']);
$ver = '0.0.5'; $base = 'https://'.$config['base']; $stop = false;

declare(ticks = 1);
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
    if (!is_dir('logs/blocklist')) mkdir('logs/blocklist');
    ini_set('error_log', 'logs/error/'.date('Y-m-d_H:i:s').'_error.log');
}

try {
    $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'],
        $config['mysql']['username'], $config['mysql']['password'], [PDO::ATTR_PERSISTENT => true]);
    if (isset($argv[1])) switch ($argv[1]) {
        case 'worker': echo date('[Y-m-d H:i:s]').' Start running worker ...',"\n"; while (!$stop) worker(); echo date('[Y-m-d H:i:s]').' Worker stopped',"\n"; break;
        case 'block':
            if (isset($argv[2]) && isset($argv[3])) {
                $type = $argv[2];
                $target = $argv[3];
                $club = isset($argv[4]) ? $argv[4] : null;
                block($type, $target, $club);
            } else {
                echo "Usage: php cli.php block <user|instance> <target> [<club>]\n";
            }
            break;
        case 'unblock':
            if (isset($argv[2]) && isset($argv[3])) {
                $type = $argv[2];
                $target = $argv[3];
                $club = isset($argv[4]) ? $argv[4] : null;
                unblock($type, $target, $club);
            } else {
                echo "Usage: php cli.php unblock <user|instance> <target> [<club>]\n";
            }
            break;
        case 'list-blocks':
            $club = isset($argv[2]) ? $argv[2] : null;
            listBlocks($club);
            break;
        case 'export-blocks':
            $club = isset($argv[2]) ? $argv[2] : null;
            exportBlocks($club);
            break;
        case 'import-blocks':
            if (isset($argv[2]) && isset($argv[3])) {
                $type = $argv[2];
                $file_path = $argv[3];
                $club = isset($argv[4]) ? $argv[4] : null;
                if ($type !== 'user' && $type !== 'instance') {
                    echo "Error: Type must be 'user' or 'instance'.\n";
                } else {
                    importBlocks($type, $file_path, $club);
                }
            } else {
                echo "Usage: php cli.php import-blocks <user|instance> <file_path> [<club>]\n";
            }
            break;
        default: echo 'Unknown parameters',"\n"; break;
    }
} catch (PDOException $e) {
    exit('Error: '.$e->getMessage()."\n");
}
