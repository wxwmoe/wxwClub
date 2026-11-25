<?php

define('APP_ROOT', dirname(__DIR__, 2)); $dir = $argv[1]; $job = $argv[2];
require($dir.'/config.php'); require_once(APP_ROOT.'/app/functions.php');
require_once(APP_ROOT.'/tests/services.php'); t_guard_database($config['mysql']['database']);
$base = 'https://'.$config['base']; $public_streams = 'https://www.w3.org/ns/activitystreams#Public';
$db = Club_DB_Connect(); $db->exec('set session innodb_lock_wait_timeout = 1');
$run = function () use ($job, $dir) {
    if ($job === 'registration') return Club_Group_Ensure('second');
    $raw = file_get_contents($dir.'/activity.json'); $activity = json_decode($raw, true);
    if ($job === 'late-edit') return Club_Inbox_Update($activity, $raw);
    return Club_Inbox_Create($activity);
};
$db->beginTransaction();
try { $run(); $db->rollBack(); file_put_contents($dir.'/ready', 'unlocked'); }
catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ((int)($e->errorInfo[1] ?? 0) !== 1205) throw $e;
    file_put_contents($dir.'/ready', 'locked');
}
$until = microtime(true) + 10;
while (!is_file($dir.'/commit') && microtime(true) < $until) usleep(10000);
if (!is_file($dir.'/commit')) exit(1);
$run();
