#!/usr/bin/env php
<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

/* 测试的唯一命令入口。没有框架也没有 composer，跟 src/ 一样只用 PHP 自带的东西：一个断言函数加一个计数器，失败打印期望和实际，退出码非零。
 * 要验的东西全是「给定输入，函数返回什么」和「给定库状态，跑完之后库里是什么」，两句 if 就够，引入 PHPUnit 只是多一个 vendor 目录和一套 composer 流程。
 *
 *   php tests/run.php                  全部，需要 MySQL
 *   php tests/run.php pure             只跑不碰数据库的那些，7.3 到 8.4 都能跑
 *   php tests/run.php state schema     指定若干组
 *
 * 数据库从环境变量取（MYSQL_HOST / MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD），默认 127.0.0.1 上的 wxwclub_test。
 * 那个库会被反复清空重建，绝不能指向生产库；库名里不带 test 的话下面会直接拒绝跑。
 *
 * 日志默认 silent，测试不该在仓库里堆出 logs/。要看某一条为什么没走到，CLUB_TEST_LOG=debug 打开。 */

define('APP_ROOT', dirname(__DIR__));
define('TEST_ROOT', __DIR__);

// config.php 不在仓库里，这里现搭一份。base 是 fixtures 里那些报文投递的目标域名，群组名也跟着它们走
$config = [
    'base' => 'local.example',
    'mysql' => [
        'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
        'database' => getenv('MYSQL_DATABASE') ?: 'wxwclub_test',
        'username' => getenv('MYSQL_USER') ?: 'root',
        'password' => (string)getenv('MYSQL_PASSWORD')
    ],
    'default' => ['avatar' => 'https://local.example/a.png', 'banner' => 'https://local.example/b.png', 'summary' => '<p>:club_name:</p>', 'nickname' => ':club_name:', 'infoname' => []],
    'node' => [
        'name' => 'test', 'description' => 'test', 'timezone' => 'UTC',
        'maintainer' => ['name' => '@admin@local.example', 'email' => 'admin@local.example'],
        'language' => 'en', 'inbox-verify' => true,
        'log-level' => getenv('CLUB_TEST_LOG') ?: 'silent', 'log-retention' => 30
    ],
    'dns' => ['resolver' => [['url' => 'https://one.one.one.one/dns-query', 'ip' => ['1.1.1.1']]], 'timeout' => 5, 'connect-timeout' => 3],
    'worker' => ['delivery' => 1, 'probe' => 1],
    'club' => ['open-registrations' => true, 'create-limit' => 0, 'suspended-names' => [], 'relay-limit' => 512, 'limits' => [], 'system-name' => 'system'],
    'notice' => ['enabled' => true, 'limit' => 20, 'retention' => 30]
];

require_once(APP_ROOT.'/src/function.php');
date_default_timezone_set('UTC');

// src/bootstrap.php 里定义的全局，这里得照抄一遍：入站分派靠 $base 认出「投给本站哪个群组」，靠 $public_streams 认出「这是公开投稿」，
// 两个都是无遮拦读的，缺了不会报错，只会让每一条 Create 静默地不匹配任何群组
$ver = '0.0.6'; $base = 'https://'.$config['base'];
$public_streams = 'https://www.w3.org/ns/activitystreams#Public';

$t_pass = 0; $t_fail = 0;

function t_group($name) {
    echo "\n== ", $name, "\n";
}

function t_ok($condition, $what) {
    global $t_pass, $t_fail;
    if ($condition) { $t_pass++; return true; }
    $t_fail++; echo '  FAIL  ', $what, "\n";
    return false;
}

// 期望和实际都打出来，只说「不相等」的话每次失败都要回去加一行 var_dump
function t_is($actual, $expected, $what) {
    global $t_pass, $t_fail;
    if ($actual === $expected) { $t_pass++; return true; }
    $t_fail++;
    echo '  FAIL  ', $what, "\n        expected: ", t_show($expected), "\n        actual:   ", t_show($actual), "\n";
    return false;
}

function t_show($value) {
    if (is_bool($value)) return $value ? 'true' : 'false';
    if ($value === null) return 'null';
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($value) ? "'".$value."'" : (string)$value;
}

// 表驱动的一行：每行是 [参数..., 期望]，最后一项是期望值，前面的原样喂给被测函数
function t_table($what, $call, $rows) {
    foreach ($rows as $row) {
        $expected = array_pop($row);
        t_is(call_user_func_array($call, $row), $expected, $what.'('.implode(', ', array_map('t_show', $row)).')');
    }
}

/* ---- 数据库 ---- */

// 库名不带 test 直接拒绝：下面每一组都会先把所有表删掉重建
function t_db() {
    global $config;
    if (strpos($config['mysql']['database'], 'test') === false) exit("Refusing to run: MYSQL_DATABASE '".$config['mysql']['database']."' does not look like a test database\n");
    return Club_DB_Connect();
}

// 清空到一张表都不剩。外键成环时按名字删不掉，先关掉检查
function t_db_reset() {
    global $db;
    $db->exec('set foreign_key_checks = 0');
    foreach ($db->query('show tables')->fetchAll(PDO::FETCH_COLUMN, 0) as $table) $db->exec('drop table `'.$table.'`');
    $db->exec('set foreign_key_checks = 1');
}

// 按 tools/wxwclub.sql 装一份当前版本的库。按分号切句对这份文件够用：里面没有存储过程，字符串里也没有分号
function t_db_import() {
    global $db;
    t_db_reset();
    foreach (explode(';', file_get_contents(APP_ROOT.'/tools/wxwclub.sql')) as $statement) if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) !== '') $db->exec($statement);
}

function t_row($sql, $params = []) {
    global $db; $pdo = $db->prepare($sql); $pdo->execute($params);
    return $pdo->fetch(PDO::FETCH_ASSOC);
}

function t_one($sql, $params = []) {
    global $db; $pdo = $db->prepare($sql); $pdo->execute($params);
    return $pdo->fetch(PDO::FETCH_COLUMN, 0);
}

function t_exec($sql, $params = []) {
    global $db; $pdo = $db->prepare($sql); $pdo->execute($params);
    return $pdo->rowCount();
}

/* ---- 入口 ---- */

// 值是「要不要数据库」
$groups = ['pure' => false, 'state' => true, 'schema' => true, 'activitypub' => true];
$argv[1] = isset($argv[1]) ? $argv[1] : 'all';

// 每条 fixture 都在自己的进程里重放，这是那个子进程的入口，父进程在 tests/activitypub.php 里
if ($argv[1] === 'replay') {
    require(TEST_ROOT.'/activitypub.php');
    t_db();
    t_ap_replay($argv[2], $argv[3]);
    echo '#counts ', $t_pass, ' ', $t_fail, "\n";
    exit($t_fail ? 1 : 0);
}

$run = $argv[1] === 'all' ? array_keys($groups) : array_slice($argv, 1);
foreach ($run as $name) if (!isset($groups[$name])) exit('Unknown group: '.$name."\n  known: all, ".implode(', ', array_keys($groups))."\n");

// 需要库的组先连一次。连不上就是连不上，不能悄悄跳过 —— CI 里那等于测试全绿但一条都没跑
foreach ($run as $name) if ($groups[$name]) { t_db(); break; }
foreach ($run as $name) require(TEST_ROOT.'/'.$name.'.php');
if (in_array('activitypub', $run, true)) t_ap_run();

echo "\n", $t_fail ? 'FAILED' : 'ok', ': ', $t_pass, ' passed, ', $t_fail, " failed\n";
exit($t_fail ? 1 : 0);
