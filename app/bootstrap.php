<?php

define('APP_ROOT', dirname(__DIR__));
require(APP_ROOT.'/config.php');
require_once(APP_ROOT.'/app/functions.php');

list($config_fatal, $config_warn) = Club_Config_Validate();
if ($config_fatal) {
    Club_Log_Event('error', 'startup blocked, configuration is invalid', ['problems' => $config_fatal, 'sapi' => PHP_SAPI]);
    if (PHP_SAPI == 'cli') {
        fwrite(STDERR, "Error: config.php is invalid\n  ".implode("\n  ", $config_fatal)."\n");
        exit(1);
    }
    http_response_code(500); exit('Error: config.php is invalid');
}

$ver = '0.0.7'; $base = 'https://'.$config['base'];
$public_streams = 'https://www.w3.org/ns/activitystreams#Public';
date_default_timezone_set($config['node']['timezone']);

Club_Log_Sync_Error();

set_exception_handler(function ($e) {
    $where = $e->getFile().':'.$e->getLine();
    Club_Log_Event('error', 'uncaught '.get_class($e), ['error' => $e->getMessage(), 'at' => $where]);
    error_log('Uncaught '.get_class($e).': '.$e->getMessage().' in '.$where."\n".$e->getTraceAsString());
    if (PHP_SAPI == 'cli') exit(1);
    if (!headers_sent()) Club_HTTP_Respond(['message' => 'Internal error'], 'json', 500);
});

if (PHP_SAPI != 'cli') register_shutdown_function('Club_Stat_Request');

try {
    Club_DB_Connect();
} catch (PDOException $e) {
    Club_Log_Event('error', 'database connection failed', ['error' => $e->getMessage(), 'sapi' => PHP_SAPI, 'pid' => getmypid()]);
    if (PHP_SAPI == 'cli') {
        fwrite(STDERR, 'Error: '.$e->getMessage()."\n");
        exit(1);
    }
    http_response_code(500); exit('Error: '.$e->getMessage());
}

if (PHP_SAPI != 'cli' && ($schema = Club_DB_Version()) !== DB_VERSION) {
    $state = $schema < DB_VERSION ? 'behind' : 'newer than this code';
    $action = $schema < DB_VERSION ? 'run the worker to merge it' : 'deploy code matching the database schema';
    header('Retry-After: 60');
    Club_Log_Event('error', 'request blocked, database schema mismatch', ['schema' => $schema, 'expected' => DB_VERSION, 'state' => $state, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
    Club_HTTP_Respond(['message' => 'Database schema '.$schema.' is '.$state.' (expected '.DB_VERSION.'), '.$action], 'json', 503);
    exit;
}
