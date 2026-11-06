<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

define('APP_ROOT', dirname(__DIR__));
define('TEST_ROOT', __DIR__);

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
        'language' => 'en', 'inbox-verify' => true, 'proxy' => '', 'proxy-scope' => 'all', 'proxy-mode' => 'proxy-only',
        'log-level' => getenv('CLUB_TEST_LOG') ?: 'silent', 'log-retention' => 30
    ],
    'dns' => ['resolver' => [['url' => 'https://one.one.one.one/dns-query', 'ip' => ['1.1.1.1']]], 'timeout' => 5, 'connect-timeout' => 3],
    'worker' => ['delivery' => ['follow' => 1, 'notice' => 1, 'announce' => 1, 'relay' => 1], 'probe' => 1],
    'club' => ['open-registrations' => true, 'create-limit' => 0, 'suspended-names' => [], 'relay-limit' => 512, 'limits' => [], 'system-name' => 'system'],
    'notice' => ['enabled' => true, 'limit' => 20, 'retention' => 30]
];

require_once(APP_ROOT.'/app/functions.php');
date_default_timezone_set('UTC');

$ver = '0.0.7'; $base = 'https://'.$config['base'];
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

function t_table($what, $call, $rows) {
    foreach ($rows as $row) {
        $expected = array_pop($row);
        t_is(call_user_func_array($call, $row), $expected, $what.'('.implode(', ', array_map('t_show', $row)).')');
    }
}

// Database tests drop every table in the selected database.
function t_db() {
    global $config;
    if (strpos($config['mysql']['database'], 'test') === false) exit("Refusing to run: MYSQL_DATABASE '".$config['mysql']['database']."' does not look like a test database\n");
    return Club_DB_Connect();
}

function t_db_reset() {
    global $db;
    $db->exec('set foreign_key_checks = 0');
    foreach ($db->query('show tables')->fetchAll(PDO::FETCH_COLUMN, 0) as $table) $db->exec('drop table `'.$table.'`');
    $db->exec('set foreign_key_checks = 1');
}

function t_db_import() {
    global $db;
    t_db_reset();
    foreach (explode(';', file_get_contents(APP_ROOT.'/app/database/schema.sql')) as $statement) if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) !== '') $db->exec($statement);
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

$groups = ['pure' => false, 'state' => true, 'schema' => true, 'activitypub' => true];
$argv[1] = isset($argv[1]) ? $argv[1] : 'all';

// Each replay needs fresh request-local state.
if ($argv[1] === 'replay') {
    require(TEST_ROOT.'/groups/activitypub.php');
    t_db();
    t_ap_replay($argv[2], $argv[3]);
    echo '#counts ', $t_pass, ' ', $t_fail, "\n";
    exit($t_fail ? 1 : 0);
}

$run = $argv[1] === 'all' ? array_keys($groups) : array_slice($argv, 1);
foreach ($run as $name) if (!isset($groups[$name])) exit('Unknown group: '.$name."\n  known: all, ".implode(', ', array_keys($groups))."\n");

foreach ($run as $name) if ($groups[$name]) { t_db(); break; }
foreach ($run as $name) require(TEST_ROOT.'/groups/'.$name.'.php');
if (in_array('activitypub', $run, true)) t_ap_run();

echo "\n", $t_fail ? 'FAILED' : 'ok', ': ', $t_pass, ' passed, ', $t_fail, " failed\n";
exit($t_fail ? 1 : 0);
