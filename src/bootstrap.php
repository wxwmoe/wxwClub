<?php
/* index.php 和 cli.php 共用的启动流程，两边只差入口文件和跑什么 */

define('APP_ROOT', dirname(__DIR__));
require(APP_ROOT.'/config.php');
require_once(APP_ROOT.'/src/function.php');

$ver = '0.0.6'; $base = 'https://'.$config['base'];
$public_streams = 'https://www.w3.org/ns/activitystreams#Public';
date_default_timezone_set($config['node']['timezone']);

// 其他日志目录由 Club_Log_Write 按需建，这里只准备 error/：
// ini_set 之后由 PHP 自己写文件，不经过我们的入口。按天一个文件，
// 按请求分的话每秒都能生成一个，查一次错误要翻几千个文件
Club_Log_Error_Path();

set_exception_handler(function ($e) {
    $where = $e->getFile().':'.$e->getLine();
    Club_Log_Event('error', 'uncaught '.get_class($e), ['error' => $e->getMessage(), 'at' => $where]);
    error_log('Uncaught '.get_class($e).': '.$e->getMessage().' in '.$where."\n".$e->getTraceAsString());
    if (PHP_SAPI != 'cli' && !headers_sent()) Club_Json_Output(['message' => 'Internal error'], 0, 500);
});

try {
    $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'].';charset=utf8mb4',
        $config['mysql']['username'], $config['mysql']['password'],
        [PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    if (PHP_SAPI == 'cli') exit('Error: '.$e->getMessage()."\n");
    http_response_code(500); exit('Error: '.$e->getMessage());
}
