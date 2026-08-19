<?php require_once(APP_ROOT.'/app/database/migrate.php');

/* 结构合并。这一组要回答的就是 AGENTS.md 里那四问：全新导入、旧版本升上来、中断之后重跑、第二次是不是 no-op。
 *
 * 最要紧的是第一条和第二条的交叉：app/database/schema.sql 是全新安装唯一的入口，它不跑合并也不跑 validator，只靠肉眼跟迁移终态对。
 * 这里把两条路各走一遍再逐列逐索引地比 —— 快照漂了的话，漂出来的差别只有全新安装的站会踩到，而那种站恰恰是最没人盯着的。 */

// information_schema 里的完整结构。列顺序不进比较（AGENTS.md 允许它不同，每条 INSERT 都写了列名），外键名也不进（各历史版本叫法不一样，按列反查）
function t_schema_dump() {
    global $db;
    $out = ['table' => [], 'column' => [], 'index' => [], 'foreign' => []];
    foreach ($db->query('select `table_name`, `table_collation` from `information_schema`.`tables` where `table_schema` = database()')->fetchAll(PDO::FETCH_NUM) as $row)
        $out['table'][$row[0]] = strtolower((string)$row[1]);
    // 5.7 仍把整数显示宽度写进 column_type，8.0 已经省略，跟 Club_Schema_Column 用同一条正则抹平
    foreach ($db->query('select `table_name`, `column_name`, `column_type`, `collation_name`, `is_nullable`, `column_default`, `extra`'.
        ' from `information_schema`.`columns` where `table_schema` = database()')->fetchAll(PDO::FETCH_NUM) as $row)
        $out['column'][$row[0].'.'.$row[1]] = implode(' ', [preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($row[2])),
            strtolower((string)$row[3]), $row[4] === 'YES' ? 'null' : 'not-null', 'default='.($row[5] === null ? '-' : $row[5]), strtolower((string)$row[6])]);
    $columns = [];
    foreach ($db->query('select `table_name`, `index_name`, `non_unique`, `column_name` from `information_schema`.`statistics`'.
        ' where `table_schema` = database() order by `seq_in_index`')->fetchAll(PDO::FETCH_NUM) as $row) {
        $key = $row[0].'.'.$row[1];
        $out['index'][$key] = $row[2] ? '' : 'unique ';
        $columns[$key][] = $row[3];
    }
    foreach ($columns as $key => $names) $out['index'][$key] .= '('.implode(',', $names).')';
    foreach ($db->query('select `k`.`table_name`, `k`.`column_name`, `k`.`referenced_table_name`, `k`.`referenced_column_name`, `r`.`update_rule`, `r`.`delete_rule`'.
        ' from `information_schema`.`key_column_usage` `k` join `information_schema`.`referential_constraints` `r` on `r`.`constraint_schema` = `k`.`constraint_schema`'.
        ' and `r`.`table_name` = `k`.`table_name` and `r`.`constraint_name` = `k`.`constraint_name`'.
        ' where `k`.`table_schema` = database() and `k`.`referenced_table_name` is not null')->fetchAll(PDO::FETCH_NUM) as $row)
        $out['foreign'][$row[0].'.'.$row[1]] = $row[2].'.'.$row[3].' on update '.strtoupper($row[4]).' on delete '.strtoupper($row[5]);
    foreach ($out as $section => $rows) { ksort($rows); $out[$section] = $rows; }
    return $out;
}

// 差在哪一列、哪一个索引，说不出来的话对着两份 SHOW CREATE TABLE 肉眼比才是真的费时间
function t_schema_diff($expected, $actual) {
    $problems = [];
    foreach ($expected as $section => $rows) {
        foreach ($rows as $key => $value) {
            if (!isset($actual[$section][$key])) $problems[] = $section.' '.$key.' is missing';
            elseif ($actual[$section][$key] !== $value) $problems[] = $section.' '.$key.': expected '.$value.', got '.$actual[$section][$key];
        }
        foreach (array_keys($actual[$section]) as $key) if (!isset($rows[$key])) $problems[] = $section.' '.$key.' is unexpected';
    }
    return $problems;
}

// 合并会往 stdout 打几十行进度，只在出事时才有人想看
function t_schema_merge() {
    ob_start();
    try { $version = Club_Migrate_Run(); }
    catch (Throwable $e) { return [null, "\n        ".$e->getMessage()."\n        ".str_replace("\n", "\n        ", trim(ob_get_clean()))]; }
    ob_end_clean();
    return [$version, ''];
}

// 把库推到某一个中间版本。合并框架只认 DB_VERSION 这一个终点，想停在半路只能照它那几步自己走一遍
function t_schema_upto($version) {
    ob_start();
    Club_Migrate_Ensure_Meta();
    for ($step = 1; $step <= $version; $step++) {
        require_once(APP_ROOT.'/app/database/steps/'.$step.'.php');
        call_user_func('Club_Migrate_'.$step);
        call_user_func('Club_Migrate_'.$step.'_Validate');
        Club_DB_Version($step);
    }
    ob_end_clean();
}

function t_schema_sql($file) {
    global $db;
    t_db_reset();
    foreach (explode(';', file_get_contents($file)) as $statement) if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) !== '') $db->exec($statement);
}

t_group('schema / migration steps');

// 版本号跟步骤文件对不上是部署问题，装了一半的代码继续跑更危险
for ($version = 1; $version <= DB_VERSION; $version++) {
    $file = APP_ROOT.'/app/database/steps/'.$version.'.php';
    if (!t_ok(is_file($file), 'app/database/steps/'.$version.'.php exists')) continue;
    require_once($file);
    t_ok(function_exists('Club_Migrate_'.$version), 'Club_Migrate_'.$version.'() is callable');
    t_ok(function_exists('Club_Migrate_'.$version.'_Validate'), 'Club_Migrate_'.$version.'_Validate() asserts the end state');
}
t_ok(!is_file(APP_ROOT.'/app/database/steps/'.(DB_VERSION + 1).'.php'), 'no step file above DB_VERSION');

t_group('schema / fresh install');

t_db_import();
// 装完就该是终态。meta.schema 忘了改的话，每个全新安装第一次启动都会触发一次空合并
t_is(Club_DB_Version(), DB_VERSION, 'app/database/schema.sql installs at DB_VERSION');
$snapshot = t_schema_dump();
list($version, $error) = t_schema_merge();
t_is($version, DB_VERSION, 'a fresh install needs no merge'.$error);
t_is(t_schema_diff($snapshot, t_schema_dump()), [], 'a fresh install is left untouched');

t_group('schema / upgrade from a legacy database');

// 每份夹具都是某次历史提交里 schema.sql 的原样拷贝再加几行数据，不从 steps 反推历史结构。按文件名从旧到新排，后面几组接着最新那份跑。
$fixtures = glob(TEST_ROOT.'/fixtures/schema/*.sql');
foreach ($fixtures as $fixture) {
    $from = basename($fixture, '.sql');
    t_schema_sql($fixture);
    $before = Club_DB_Version();
    t_ok($before >= 0 && $before < DB_VERSION, $from.': the fixture predates the current schema');
    list($version, $error) = t_schema_merge();
    t_is($version, DB_VERSION, $from.': merges all the way up'.$error);
    // 这一条就是快照漂移的闸门。差别出现在这里，说明改结构时漏了 app/database/schema.sql 那一半
    t_is(t_schema_diff($snapshot, t_schema_dump()), [], $from.': ends at exactly what app/database/schema.sql installs');
    // 回填真的搬过东西，不是在空表上跑了一遍
    t_ok((int)t_one('select `timestamp` from `clubs` where `name` = \'test\'') > 0, $from.': the club survives with an epoch timestamp');
    t_is(t_one('select `clubs` from `activities` where `id` = 1'), '["test"]', $from.': the activity keeps its club');
    t_is((int)t_one('select count(*) from `followers`'), 1, $from.': existing followers are kept');
    if ($from === '52f1d01-final') t_schema_scheduling($from);
    // 存量 actor 的 handle 一个都没跟对端核对过，合并不能替它们声称确认过 —— 那会让 WebFinger 的 410 直接作用在一个猜出来的 handle 上
    if ($from === 'e391bd1-final') t_is((int)t_one('select `webfinger` from `users` where `uid` = 1'), 0, $from.': legacy actors stay unconfirmed');
    if ($from === 'ed9e358-final') {
        t_is(t_one('select `type` from `tasks` where `tid` = 1'), 'push', $from.': legacy task names are not rewritten');
        t_is((int)t_one('select `relay_at` from `endpoints` where `url` = :url', [':url' => 'https://remote.example/inbox']), 1666972800,
            $from.': legacy push backlog is scheduled as relay');
    }
}

// 只有 52f1d01 那份带着调度侧的存量行，而它们恰恰是这次合并里最容易整批消失的东西：结构比对看不见行，
// 第 2 版又把 queues 和 blacklist 的主键和大半列都换掉了。搬丢一张表的表现是队列凭空清空，搬错一列的表现是拉黑目标下一秒就被重新探活
function t_schema_scheduling($from) {
    t_is((int)t_one('select count(*) from `announces`'), 1, $from.': the announce survives');
    t_is(t_one('select `content` from `announces` where `id` = 1'), 'legacy announce', $from.': the announce keeps its content through the widen to mediumtext');
    t_is((int)t_one('select count(*) from `tasks`'), 1, $from.': the task survives');
    t_is(t_one('select `jsonld` from `tasks` where `tid` = 1'), '{"type":"Announce"}', $from.': the task keeps its payload');

    // due_at 取自旧的 timestamp，retries 取自旧的 retry
    $queue = t_row('select `type`, `target`, `due_at`, `retries` from `queues` where `id` = 1') ?: [];
    t_is($queue['type'] ?? '-', 'relay', $from.': legacy queues enter the lowest type');
    t_is(isset($queue['target']) ? $queue['target'] : '-', 'https://remote.example/inbox', $from.': the queue row survives with its target');
    t_is((int)($queue['due_at'] ?? -1), 1666972800, $from.': queues.due_at is backfilled from the old timestamp');
    t_is((int)($queue['retries'] ?? -1), 0, $from.': queues.retries is backfilled from the old retry');

    // created_at 来自 create 列，check_at 来自 timestamp 列。两个来源不同，写反了这两条就对调
    $black = t_row('select `created_at`, `check_at`, `checks` from `blacklist` where `target` = :target', [':target' => 'https://dead.example/inbox']) ?: [];
    t_is((int)($black['created_at'] ?? -1), 1666972800, $from.': blacklist.created_at comes from the old create column');
    t_is((int)($black['check_at'] ?? -1), 0, $from.': blacklist.check_at comes from the old timestamp column');
    t_is((int)($black['checks'] ?? -1), 0, $from.': blacklist.checks comes from the old retry column');
    t_is((int)t_one('select count(*) from `blacklist` where `restore_pending_at` is null'), 1, $from.': a migrated blacklist row is not pending restore');

    // 控制行从 queues 和 blacklist 两边补出来，拉黑的那条不排期，而不排期的行必须带着空置起点
    t_is((int)t_one('select count(*) from `endpoints`'), 2, $from.': one control row per target');
    $live = t_row('select `next_at`, `idle_since`, `fails`, `fail_since`, `retry_at` from `endpoints` where `url` = :url', [':url' => 'https://remote.example/inbox']) ?: [];
    t_is((int)($live['next_at'] ?? -1), 1666972800, $from.': a queued target is scheduled at its earliest due_at');
    t_is((int)($live['idle_since'] ?? -1), 0, $from.': a scheduled control row carries no idle clock');
    t_is(((int)($live['fails'] ?? -1)) + ((int)($live['fail_since'] ?? -1)) + ((int)($live['retry_at'] ?? -1)), 0, $from.': a rebuilt control row starts healthy');
    t_is((int)t_one('select count(*) from `endpoints` where `url` = :url and `next_at` is null and `idle_since` > 0',
        [':url' => 'https://dead.example/inbox']), 1, $from.': a blacklisted target is unscheduled and carries an idle clock');
}

t_group('schema / resume after an interrupted merge');

// 崩在 DDL 之后、写版本号之前是最坏的一种：这一步的结构已经落库，meta 还停在上一版，重启之后它会整个再跑一遍。
// 能出现的组合只有「结构在第 N 版、meta 停在 N-1」这一种 —— 版本号只在一步跑完之后才写，而且只往前走。
// 所以不能拿已经合并到底的库把 meta 倒回 0 充数：那种状态现实里不存在，倒回去只是让第 1 版对着第 4 版的结构瞎判，
// 报出来的错跟真正的中断恢复没有关系。每一轮都从最老的那份夹具重新装起，手工推到第 N 版，再把版本号退一格
for ($step = 1; $step <= DB_VERSION; $step++) {
    t_schema_sql($fixtures[0]);
    t_schema_upto($step);
    Club_DB_Version($step - 1);
    list($result, $error) = t_schema_merge();
    t_is($result, DB_VERSION, 'step '.$step.' re-runs on a database that already has it'.$error);
    t_is(t_schema_diff($snapshot, t_schema_dump()), [], 'step '.$step.' re-run still ends at the snapshot');
}

t_group('schema / second run');

list($version, $error) = t_schema_merge();
t_is($version, DB_VERSION, 'a second merge is a no-op'.$error);
t_is(t_schema_diff($snapshot, t_schema_dump()), [], 'a second merge changes nothing');
// checkpoint 的生命周期不能超出它那一版，留下来的话下一次合并会读到上一次的半截状态
t_is((int)t_one('select count(*) from `meta` where `name` like \'migration.%\''), 0, 'no migration checkpoint outlives its own version');

// 回滚或滚动部署中的旧代码不认识新结构，继续写会损坏数据
Club_DB_Version(DB_VERSION + 1);
list($version, $error) = t_schema_merge();
t_is($version, null, 'a database newer than the code is refused outright');
t_is(t_schema_diff($snapshot, t_schema_dump()), [], 'a refused merge changes nothing');
Club_DB_Version(DB_VERSION);
