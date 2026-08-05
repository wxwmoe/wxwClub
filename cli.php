#!/usr/bin/env php
<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

require(__DIR__.'/src/bootstrap.php');
require_once(APP_ROOT.'/src/worker.php');

$stop = false;
declare(ticks = 1);
pcntl_signal(SIGINT, 'shutdown');
pcntl_signal(SIGTERM, 'shutdown');

function shutdown() {
    global $stop; $stop = true;
    Club_Log_Console('info', 'Stopping, please wait ...');
};

if (isset($argv[1])) switch ($argv[1]) {
    case 'worker':
        Club_Log_Console('info', 'Start running worker ...');
        while (!$stop) {
            try { worker(); }
            // 之前只 echo 到 stdout，进程被托管起来跑的话这些报错等于没有
            catch (PDOException $e) { Club_Log_Console('error', 'Database error: '.$e->getMessage()); sleep(1); }
        }
        Club_Log_Console('info', 'Worker stopped'); break;
    default: echo 'Unknown parameters',"\n"; break;
}
