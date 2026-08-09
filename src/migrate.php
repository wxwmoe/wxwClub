<?php require_once(__DIR__.'/function.php');

/* 数据库合并的执行框架。步骤本身在 src/migrate/<版本号>.php 里，一个文件一个版本，函数名固定为 Club_Migrate_<版本号>()。
 * 加一次升级就是加一个文件加 DB_VERSION 加一，老库照样能从任何一个历史版本一路跑上来。
 *
 * 只有 worker 会走到这里，而且是在没有任何 web 请求和投递进程的时候：web 见到版本落后就整个入口挡住，worker 见到落后就先合并、合并完直接退出，由容器重启带起队列进程。
 *
 * DDL 会 implicit commit，中途崩了没有事务能回滚，所以不追求原子性，只追求可重入：每一小步都先问一次 information_schema，做过就跳过。
 * 库里没有 meta 表的一律当版本 0，从第一个步骤开始跑，靠这些判断逐个跳过它已经有的东西 —— 版本号只决定从哪里开始试，真正的依据永远是库当前长什么样 */

// 合并期间不能有第二个进程同时动结构。命名锁跟连接绑定，进程没了锁自然就掉，比在表里放一个要自己收拾的标志位可靠
function Club_Migrate_Run() {
    global $db;
    $from = Club_DB_Version();
    Club_Migrate_Reject_Newer($from);
    if ($from === DB_VERSION) return $from;
    $pdo = $db->query('select get_lock(\'wxwclub_migrate\', 0)');
    if (!$pdo->fetch(PDO::FETCH_COLUMN, 0)) {
        Club_Log_Console('info', 'migration skipped, another process holds the lock', ['from' => $from]);
        return $from;
    }
    try {
        Club_Migrate_Ensure_Meta();
        // 等锁期间别人可能刚合并完，重新读一次才算数
        $from = Club_DB_Version();
        Club_Migrate_Reject_Newer($from);
        for ($version = $from + 1; $version <= DB_VERSION; $version++) {
            $file = APP_ROOT.'/src/migrate/'.$version.'.php';
            $step = 'Club_Migrate_'.$version;
            if (!is_file($file)) {
                // 版本号和步骤文件对不上是部署问题，装了一半的代码继续跑更危险
                Club_Log_Console('error', 'migration step is missing',
                    ['version' => $version, 'file' => $file]);
                throw new RuntimeException('Migration step '.$version.' is missing');
            }
            require_once($file);
            if (!function_exists($step)) {
                Club_Log_Console('error', 'migration step is not callable',
                    ['version' => $version, 'function' => $step]);
                throw new RuntimeException('Migration step '.$version.' is not callable');
            }
            Club_Log_Console('info', 'migration started', ['to' => $version, 'from' => $version - 1]);
            $start = microtime(true);
            $step();
            $validate = $step.'_Validate';
            if (function_exists($validate)) $validate();
            Club_DB_Version($version);
            Club_Migrate_Assert(Club_DB_Version() === $version,
                'schema version was not stored', ['expected' => $version]);
            Club_Log_Console('info', 'migration finished',
                ['to' => $version, 'seconds' => round(microtime(true) - $start, 1)]);
        }
    } finally {
        try { $db->query('select release_lock(\'wxwclub_migrate\')'); }
        catch (PDOException $e) {
            // 命名锁随连接断开自动释放，这里的失败不该覆盖真正的合并结果
            Club_Log_Console('warning', 'migration lock release failed',
                ['error' => $e->getMessage()]);
        }
    }
    return Club_DB_Version();
}

function Club_Migrate_Reject_Newer($version) {
    if ($version <= DB_VERSION) return false;
    Club_Log_Console('error', 'database schema is newer than this code',
        ['schema' => $version, 'expected' => DB_VERSION]);
    throw new UnexpectedValueException('Database schema '.$version.' is newer than '.DB_VERSION);
}

function Club_Migrate_Ensure_Meta() {
    if (!Club_Schema_Table('meta')) {
        Club_Migrate_Exec('create meta',
            'create table `meta` ('.
            '`name` varchar(30) character set ascii collate ascii_general_ci not null,'.
            '`value` varchar(255) character set ascii collate ascii_general_ci not null,'.
            'primary key (`name`)'.
            ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
        return true;
    }
    Club_Migrate_Assert_Column('meta', 'name', 'varchar(30)', 'ascii_general_ci', false);
    Club_Migrate_Assert_Column('meta', 'value', 'varchar(255)', 'ascii_general_ci', false);
    Club_Migrate_Assert_Index('meta', 'PRIMARY', true, ['name']);
    return false;
}

function Club_Migrate_State($name, $set = null) {
    global $db;
    if (isset($set)) {
        $pdo = $db->prepare('insert into `meta`(`name`,`value`) values (:name, :value)'.
            ' on duplicate key update `value` = :value');
        $pdo->execute([':name' => $name, ':value' => $set]);
        return $set;
    }
    $pdo = $db->prepare('select `value` from `meta` where `name` = :name');
    $pdo->execute([':name' => $name]);
    return $pdo->fetch(PDO::FETCH_COLUMN, 0);
}

function Club_Schema_Table($table) {
    global $db;
    $pdo = $db->prepare('select 1 from `information_schema`.`tables`'.
        ' where `table_schema` = database() and `table_name` = :table');
    $pdo->execute([':table' => $table]);
    return (bool)$pdo->fetch(PDO::FETCH_COLUMN, 0);
}

// 列存不存在，以及它现在是什么类型和排序规则。URL 那几列改没改 collation 就是「规范化做过没有」的判据，旧库的 varchar(100) 也要靠 type 认出来
function Club_Schema_Column($table, $column) {
    global $db;
    $pdo = $db->prepare('select `column_type`, `collation_name`, `is_nullable`,'.
        ' `column_default`, `extra` from `information_schema`.`columns`'.
        ' where `table_schema` = database() and `table_name` = :table and `column_name` = :column');
    $pdo->execute([':table' => $table, ':column' => $column]);
    $row = $pdo->fetch(PDO::FETCH_NUM);
    if ($row === false) return false;
    // MySQL 5.7 仍把整数显示宽度写进 column_type，8.0 则已经省略
    $type = preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/',
        '$1', strtolower($row[0]));
    return ['type' => $type,
        'collation' => $row[1] === null ? null : strtolower($row[1]),
        'nullable' => $row[2] === 'YES', 'default' => $row[3], 'extra' => strtolower($row[4])];
}

function Club_Schema_Index($table, $index) {
    global $db;
    $pdo = $db->prepare('select `non_unique`, `column_name` from `information_schema`.`statistics`'.
        ' where `table_schema` = database() and `table_name` = :table and `index_name` = :index'.
        ' order by `seq_in_index`');
    $pdo->execute([':table' => $table, ':index' => $index]);
    $rows = $pdo->fetchAll(PDO::FETCH_NUM);
    if (!$rows) return false;
    $columns = [];
    foreach ($rows as $row) $columns[] = $row[1];
    return ['unique' => !$rows[0][0], 'columns' => $columns];
}

// 外键名各版本不一样（activitys_ibfk_4 之类），按列反查才靠得住
function Club_Schema_Foreign($table, $column) {
    $names = [];
    foreach (Club_Schema_Foreign_Info($table, $column) as $row)
        $names[] = $row['name'];
    return $names;
}

function Club_Schema_Foreign_Info($table, $column) {
    global $db;
    $pdo = $db->prepare('select `k`.`constraint_name`, `k`.`referenced_table_name`,'.
        ' `k`.`referenced_column_name`, `r`.`update_rule`, `r`.`delete_rule`'.
        ' from `information_schema`.`key_column_usage` `k`'.
        ' join `information_schema`.`referential_constraints` `r`'.
        ' on `r`.`constraint_schema` = `k`.`constraint_schema`'.
        ' and `r`.`table_name` = `k`.`table_name`'.
        ' and `r`.`constraint_name` = `k`.`constraint_name`'.
        ' where `k`.`table_schema` = database() and `k`.`table_name` = :table'.
        ' and `k`.`column_name` = :column and `k`.`referenced_table_name` is not null');
    $pdo->execute([':table' => $table, ':column' => $column]);
    $rows = [];
    while ($row = $pdo->fetch(PDO::FETCH_NUM)) $rows[] = [
        'name' => $row[0], 'table' => $row[1], 'column' => $row[2],
        'update' => strtoupper($row[3]), 'delete' => strtoupper($row[4]),
    ];
    return $rows;
}

function Club_Schema_Referenced_By($table, $column) {
    global $db;
    $pdo = $db->prepare('select `table_name`, `column_name`, `constraint_name`'.
        ' from `information_schema`.`key_column_usage`'.
        ' where `referenced_table_schema` = database() and `referenced_table_name` = :table'.
        ' and `referenced_column_name` = :column');
    $pdo->execute([':table' => $table, ':column' => $column]);
    $rows = [];
    while ($row = $pdo->fetch(PDO::FETCH_NUM))
        $rows[] = ['table' => $row[0], 'column' => $row[1], 'name' => $row[2]];
    return $rows;
}

// 一句 DDL 加一行日志。合并要跑多久、卡在哪一步，事后只能靠这些行还原
function Club_Migrate_Exec($what, $sql) {
    global $db; $start = microtime(true);
    Club_Log_Console('info', 'migration step', ['step' => $what]);
    $db->exec($sql);
    Club_Log_Console('info', 'migration step done',
        ['step' => $what, 'seconds' => round(microtime(true) - $start, 1)]);
}

// 一步要跑好几条语句、或者跑的根本不是 DDL 时用它包一层，日志跟 Club_Migrate_Exec 同形
function Club_Migrate_Step($what, $run) {
    $start = microtime(true);
    Club_Log_Console('info', 'migration step', ['step' => $what]);
    $result = $run();
    Club_Log_Console('info', 'migration step done',
        ['step' => $what, 'seconds' => round(microtime(true) - $start, 1)]);
    return $result;
}

// 只删存在的索引。历史版本的索引集各不相同，写死名字的 DROP KEY 一撞就整步失败
function Club_Migrate_DropKeys($table, $keys) {
    $drop = [];
    foreach ((array)$keys as $key) if (Club_Schema_Index($table, $key)) $drop[] = 'drop key `'.$key.'`';
    if (!$drop) return false;
    Club_Migrate_Exec($table.' drop keys', 'alter table `'.$table.'` '.implode(', ', $drop));
    return true;
}

// 只加缺的索引，已经在的按 unique 与否判断要不要重建
function Club_Migrate_AddKeys($table, $keys) {
    $add = [];
    foreach ($keys as $name => $spec) {
        $unique = strpos($spec, 'unique ') === 0;
        $columns = $unique ? substr($spec, 7) : $spec;
        preg_match_all('/`([^`]+)`/', $columns, $matches);
        if ($index = Club_Schema_Index($table, $name)) {
            if ($index['unique'] === $unique && $index['columns'] === $matches[1]) continue;
            $add[] = 'drop key `'.$name.'`';
        }
        $add[] = 'add '.($unique ? 'unique ' : '').'key `'.$name.'` ('.$columns.')';
    }
    if (!$add) return false;
    Club_Migrate_Exec($table.' keys', 'alter table `'.$table.'` '.implode(', ', $add));
    return true;
}

function Club_Migrate_Assert($condition, $check, $context = []) {
    if ($condition) return true;
    Club_Log_Console('error', 'migration validation failed',
        array_merge(['check' => $check], $context));
    throw new RuntimeException('Migration validation failed: '.$check);
}

function Club_Migrate_Assert_Column($table, $column, $type = null, $collation = null,
    $nullable = null) {
    $info = Club_Schema_Column($table, $column);
    Club_Migrate_Assert($info !== false, $table.'.'.$column.' exists');
    if ($type !== null) Club_Migrate_Assert($info['type'] === $type,
        $table.'.'.$column.' type', ['actual' => $info['type'], 'expected' => $type]);
    if ($collation !== null) Club_Migrate_Assert($info['collation'] === $collation,
        $table.'.'.$column.' collation',
        ['actual' => $info['collation'], 'expected' => $collation]);
    if ($nullable !== null) Club_Migrate_Assert($info['nullable'] === $nullable,
        $table.'.'.$column.' nullability',
        ['actual' => $info['nullable'], 'expected' => $nullable]);
    return $info;
}

function Club_Migrate_Assert_Index($table, $index, $unique, $columns) {
    $info = Club_Schema_Index($table, $index);
    Club_Migrate_Assert($info !== false, $table.'.'.$index.' index exists');
    Club_Migrate_Assert($info['unique'] === $unique && $info['columns'] === $columns,
        $table.'.'.$index.' index definition',
        ['actual' => $info, 'expected' => ['unique' => $unique, 'columns' => $columns]]);
    return $info;
}

function Club_Migrate_Assert_Foreign($table, $column, $referencedTable, $referencedColumn,
    $update = 'CASCADE', $delete = 'CASCADE') {
    $actual = Club_Schema_Foreign_Info($table, $column);
    $expected = ['table' => $referencedTable, 'column' => $referencedColumn,
        'update' => strtoupper($update), 'delete' => strtoupper($delete)];
    $matches = count($actual) === 1 && $actual[0]['table'] === $expected['table'] &&
        $actual[0]['column'] === $expected['column'] && $actual[0]['update'] === $expected['update'] &&
        $actual[0]['delete'] === $expected['delete'];
    Club_Migrate_Assert($matches, $table.'.'.$column.' foreign key definition',
        ['actual' => $actual, 'expected' => $expected]);
    return $actual[0];
}

function Club_Migrate_Ensure_Foreign($table, $column, $name, $referencedTable,
    $referencedColumn, $update = 'CASCADE', $delete = 'CASCADE') {
    $actual = Club_Schema_Foreign_Info($table, $column);
    if (count($actual) === 1 && $actual[0]['table'] === $referencedTable &&
        $actual[0]['column'] === $referencedColumn && $actual[0]['update'] === strtoupper($update) &&
        $actual[0]['delete'] === strtoupper($delete)) return false;
    foreach ($actual as $foreign) Club_Migrate_Exec($table.' drop foreign key '.$foreign['name'],
        'alter table `'.$table.'` drop foreign key `'.$foreign['name'].'`');
    Club_Migrate_Exec($table.' add foreign key '.$name, 'alter table `'.$table.'`'.
        ' add constraint `'.$name.'` foreign key (`'.$column.'`) references `'.
        $referencedTable.'` (`'.$referencedColumn.'`) on delete '.$delete.' on update '.$update);
    return true;
}

// 旧的有符号列进 unsigned 之前先看一眼。抬到 0 是有损的，不能不吭声
function Club_Migrate_Negative($table, $columns) {
    global $db;
    $where = [];
    foreach ($columns as $column) $where[] = '`'.$column.'` < 0';
    $pdo = $db->query('select count(*) from `'.$table.'` where '.implode(' or ', $where));
    if ($rows = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0))
        Club_Log_Console('warning', 'negative values clamped to zero',
            ['table' => $table, 'columns' => implode(',', $columns), 'rows' => $rows]);
    return $rows;
}

// datetime 存的是本地时间文本，int 存的是 epoch，直接 MODIFY 会得到 20260805120000 这样的数字。只能新开一列、UNIX_TIMESTAMP() 转过去，再把旧列删掉
function Club_Migrate_Datetime($table, $from, $to) {
    if (!Club_Schema_Column($table, $from)) return false;
    if (!Club_Schema_Column($table, $to))
        Club_Migrate_Exec($table.' add '.$to,
            'alter table `'.$table.'` add `'.$to.'` int not null default 0 after `'.$from.'`');
    Club_Migrate_Exec($table.' convert '.$from,
        'update `'.$table.'` set `'.$to.'` = coalesce(unix_timestamp(`'.$from.'`), 0)');
    Club_Migrate_Exec($table.' drop '.$from,
        'alter table `'.$table.'` drop column `'.$from.'`, modify `'.$to.'` int not null');
    return true;
}
