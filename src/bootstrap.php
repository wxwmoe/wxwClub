<?php
/* index.php 和 cli.php 共用的启动流程，两边只差入口文件和跑什么 */

define('APP_ROOT', dirname(__DIR__));
require(APP_ROOT.'/config.php');
require_once(APP_ROOT.'/src/function.php');

$ver = '0.0.6'; $base = 'https://'.$config['base'];
$public_streams = 'https://www.w3.org/ns/activitystreams#Public';
date_default_timezone_set($config['node']['timezone']);

// 其他日志目录由 Club_Log_Write 按需建，这里只准备 error/：ini_set 之后由 PHP 自己写文件，不经过我们的入口。按天一个文件，按请求分的话每秒都能生成一个，查一次错误要翻几千个文件
Club_Log_Error_Path();

set_exception_handler(function ($e) {
    $where = $e->getFile().':'.$e->getLine();
    Club_Log_Event('error', 'uncaught '.get_class($e), ['error' => $e->getMessage(), 'at' => $where]);
    error_log('Uncaught '.get_class($e).': '.$e->getMessage().' in '.$where."\n".$e->getTraceAsString());
    if (PHP_SAPI == 'cli') exit(1);
    if (!headers_sent()) Club_Json_Output(['message' => 'Internal error'], 0, 500);
});

// web 请求也会走 resolver，DNS 争用和刷新等待要在这一侧留下同样的计数，否则日志里只看得见 worker 那一半，冷缓存被 FPM 抢走的情况永远对不上账
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

// 库里的结构与这份代码要求的版本不一致时，整个入口先挡住。只挡 Club_Push_Activity() 是不够的：入站活动写进本地状态之后才报错的话，对端重放会形成半处理。
// 503 + Retry-After 是明确的「稍后再来」，换成 4xx 对端就当终局拒绝，那条活动永远丢了。库比代码新同样不能放行：回滚或滚动部署中的旧代码不认识新结构，继续写会损坏数据
if (PHP_SAPI != 'cli' && ($schema = Club_DB_Version()) !== DB_VERSION) {
    $state = $schema < DB_VERSION ? 'behind' : 'newer than this code';
    $action = $schema < DB_VERSION ? 'run the worker to merge it' : 'deploy code matching the database schema';
    header('Retry-After: 60');
    Club_Log_Event('error', 'request blocked, database schema mismatch', ['schema' => $schema, 'expected' => DB_VERSION, 'state' => $state, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
    Club_Json_Output(['message' => 'Database schema '.$schema.' is '.$state.' (expected '.DB_VERSION.'), '.$action], 0, 503);
    exit;
}
