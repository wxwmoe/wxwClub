<?php require_once(APP_ROOT.'/app/database/migrate.php');

function t_schema_dump() {
    global $db;
    $out = ['table' => [], 'column' => [], 'index' => [], 'foreign' => []];
    foreach ($db->query('select `table_name`, `table_collation` from `information_schema`.`tables` where `table_schema` = database()')->fetchAll(PDO::FETCH_NUM) as $row)
        $out['table'][$row[0]] = strtolower((string)$row[1]);
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

function t_schema_merge() {
    ob_start();
    try { $version = Club_Migrate_Run(); }
    catch (Throwable $e) { return [null, "\n        ".$e->getMessage()."\n        ".str_replace("\n", "\n        ", trim(ob_get_clean()))]; }
    ob_end_clean();
    return [$version, ''];
}

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
t_is(Club_DB_Version(), DB_VERSION, 'app/database/schema.sql installs at DB_VERSION');
$snapshot = t_schema_dump();
list($version, $error) = t_schema_merge();
t_is($version, DB_VERSION, 'a fresh install needs no merge'.$error);
t_is(t_schema_diff($snapshot, t_schema_dump()), [], 'a fresh install is left untouched');

t_group('schema / upgrade from a legacy database');

$fixtures = glob(TEST_ROOT.'/fixtures/schema/*.sql');
foreach ($fixtures as $fixture) {
    $from = basename($fixture, '.sql');
    t_schema_sql($fixture);
    $before = Club_DB_Version();
    t_ok($before >= 0 && $before < DB_VERSION, $from.': the fixture predates the current schema');
    list($version, $error) = t_schema_merge();
    t_is($version, DB_VERSION, $from.': merges all the way up'.$error);
    t_is(t_schema_diff($snapshot, t_schema_dump()), [], $from.': ends at exactly what app/database/schema.sql installs');
    t_ok((int)t_one('select `timestamp` from `clubs` where `name` = \'test\'') > 0, $from.': the club survives with an epoch timestamp');
    t_is(t_one('select `clubs` from `activities` where `id` = 1'), '["test"]', $from.': the activity keeps its club');
    t_is((int)t_one('select count(*) from `followers`'), 1, $from.': existing followers are kept');
    if ($from === '52f1d01-final') t_schema_scheduling($from);
    if ($from === 'e391bd1-final') t_is((int)t_one('select `webfinger` from `users` where `uid` = 1'), 0, $from.': legacy actors stay unconfirmed');
    if ($from === 'ed9e358-final') {
        t_is(t_one('select `type` from `tasks` where `tid` = 1'), 'push', $from.': legacy task names are not rewritten');
        t_is((int)t_one('select `relay_at` from `endpoints` where `url` = :url', [':url' => 'https://remote.example/inbox']), 1666972800,
            $from.': legacy push backlog is scheduled as relay');
    }
}

function t_schema_scheduling($from) {
    t_is((int)t_one('select count(*) from `announces`'), 1, $from.': the announce survives');
    t_is(t_one('select `content` from `announces` where `id` = 1'), 'legacy announce', $from.': the announce keeps its content through the widen to mediumtext');
    t_is((int)t_one('select count(*) from `tasks`'), 1, $from.': the task survives');
    t_is(t_one('select `jsonld` from `tasks` where `tid` = 1'), '{"type":"Announce"}', $from.': the task keeps its payload');

    $queue = t_row('select `type`, `target`, `due_at`, `retries` from `queues` where `id` = 1') ?: [];
    t_is($queue['type'] ?? '-', 'relay', $from.': legacy queues enter the lowest type');
    t_is(isset($queue['target']) ? $queue['target'] : '-', 'https://remote.example/inbox', $from.': the queue row survives with its target');
    t_is((int)($queue['due_at'] ?? -1), 1666972800, $from.': queues.due_at is backfilled from the old timestamp');
    t_is((int)($queue['retries'] ?? -1), 0, $from.': queues.retries is backfilled from the old retry');

    $black = t_row('select `created_at`, `check_at`, `checks` from `blacklist` where `target` = :target', [':target' => 'https://dead.example/inbox']) ?: [];
    t_is((int)($black['created_at'] ?? -1), 1666972800, $from.': blacklist.created_at comes from the old create column');
    t_is((int)($black['check_at'] ?? -1), 0, $from.': blacklist.check_at comes from the old timestamp column');
    t_is((int)($black['checks'] ?? -1), 0, $from.': blacklist.checks comes from the old retry column');
    t_is((int)t_one('select count(*) from `blacklist` where `restore_pending_at` is null'), 1, $from.': a migrated blacklist row is not pending restore');

    t_is((int)t_one('select count(*) from `endpoints`'), 2, $from.': one control row per target');
    $live = t_row('select `next_at`, `idle_since`, `fails`, `fail_since`, `retry_at` from `endpoints` where `url` = :url', [':url' => 'https://remote.example/inbox']) ?: [];
    t_is((int)($live['next_at'] ?? -1), 1666972800, $from.': a queued target is scheduled at its earliest due_at');
    t_is((int)($live['idle_since'] ?? -1), 0, $from.': a scheduled control row carries no idle clock');
    t_is(((int)($live['fails'] ?? -1)) + ((int)($live['fail_since'] ?? -1)) + ((int)($live['retry_at'] ?? -1)), 0, $from.': a rebuilt control row starts healthy');
    t_is((int)t_one('select count(*) from `endpoints` where `url` = :url and `next_at` is null and `idle_since` > 0',
        [':url' => 'https://dead.example/inbox']), 1, $from.': a blacklisted target is unscheduled and carries an idle clock');
}

t_group('schema / resume after an interrupted merge');

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
t_is((int)t_one('select count(*) from `meta` where `name` like \'migration.%\''), 0, 'no migration checkpoint outlives its own version');

Club_DB_Version(DB_VERSION + 1);
list($version, $error) = t_schema_merge();
t_is($version, null, 'a database newer than the code is refused outright');
t_is(t_schema_diff($snapshot, t_schema_dump()), [], 'a refused merge changes nothing');
Club_DB_Version(DB_VERSION);
