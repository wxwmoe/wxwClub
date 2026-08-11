<?php require_once(APP_ROOT.'/src/migrate.php');

/* 结构合并。这一组要回答的就是 AGENTS.md 里那四问：全新导入、旧版本升上来、中断之后重跑、第二次是不是 no-op。
 *
 * 最要紧的是第一条和第二条的交叉：tools/wxwclub.sql 是全新安装唯一的入口，它不跑合并也不跑 validator，只靠肉眼跟迁移终态对。
 * 这里把两条路各走一遍再逐列逐索引地比 —— 快照漂了的话，漂出来的差别只有全新安装的站会踩到，而那种站恰恰是最没人盯着的。 */

// information_schema 里的完整结构。列顺序不进比较（AGENTS.md 允许它不同，每条 INSERT 都写了列名），外键名也不进（各历史版本叫法不一样，按列反查）
function t_schema_dump() {
    global $db;
    $out = ['table' => [], 'column' => [], 'index' => [], 'foreign' => []];
    foreach ($db->query('select `table_name`, `table_collation` from `information_schema`.`tables` where `table_schema` = database()')->fetchAll(PDO::FETCH_NUM) as $row)
        $out['table'][$row[0]] = strtolower((string)$row[1]);
    // 5.7 仍把整数显示宽度写进 column_type，8.0 已经省略，跟 Club_Schema_Column 用同一条正则抹平
    foreach ($db->query('select `table_name`, `column_name`, `column_type`, `collation_name`, `is_nullable`, `column_default`, `extra` from `information_schema`.`columns` where `table_schema` = database()')->fetchAll(PDO::FETCH_NUM) as $row)
        $out['column'][$row[0].'.'.$row[1]] = implode(' ', [preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($row[2])),
            strtolower((string)$row[3]), $row[4] === 'YES' ? 'null' : 'not-null', 'default='.($row[5] === null ? '-' : $row[5]), strtolower((string)$row[6])]);
    $columns = [];
    foreach ($db->query('select `table_name`, `index_name`, `non_unique`, `column_name` from `information_schema`.`statistics` where `table_schema` = database() order by `seq_in_index`')->fetchAll(PDO::FETCH_NUM) as $row) {
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

function t_schema_sql($file) {
    global $db;
    t_db_reset();
    foreach (explode(';', file_get_contents($file)) as $statement)
        if (trim(preg_replace('/^\s*--.*$/m', '', $statement)) !== '') $db->exec($statement);
}

t_group('schema / migration steps');

// 版本号跟步骤文件对不上是部署问题，装了一半的代码继续跑更危险
for ($version = 1; $version <= DB_VERSION; $version++) {
    $file = APP_ROOT.'/src/migrate/'.$version.'.php';
    if (!t_ok(is_file($file), 'src/migrate/'.$version.'.php exists')) continue;
    require_once($file);
    t_ok(function_exists('Club_Migrate_'.$version), 'Club_Migrate_'.$version.'() is callable');
    t_ok(function_exists('Club_Migrate_'.$version.'_Validate'), 'Club_Migrate_'.$version.'_Validate() asserts the end state');
}
t_ok(!is_file(APP_ROOT.'/src/migrate/'.(DB_VERSION + 1).'.php'), 'no step file above DB_VERSION');

t_group('schema / fresh install');

t_db_import();
// 装完就该是终态。meta.schema 忘了改的话，每个全新安装第一次启动都会触发一次空合并
t_is(Club_DB_Version(), DB_VERSION, 'tools/wxwclub.sql installs at DB_VERSION');
$snapshot = t_schema_dump();
list($version, $error) = t_schema_merge();
t_is($version, DB_VERSION, 'a fresh install needs no merge'.$error);
t_is(t_schema_diff($snapshot, t_schema_dump()), [], 'a fresh install is left untouched');

t_group('schema / upgrade from a legacy database');

t_schema_sql(TEST_ROOT.'/fixtures/schema/legacy.sql');
t_is(Club_DB_Version(), 0, 'a database without meta reads as version 0');
list($version, $error) = t_schema_merge();
t_is($version, DB_VERSION, 'a legacy database merges all the way up'.$error);
$merged = t_schema_dump();
// 这一条就是快照漂移的闸门。差别出现在这里，说明改结构时漏了 tools/wxwclub.sql 那一半
t_is(t_schema_diff($snapshot, $merged), [], 'tools/wxwclub.sql matches the end state of the merge');
// 回填真的搬过东西：datetime 转成 epoch、cid 变成群组名列表
t_is((int)t_one('select `timestamp` from `clubs` where `name` = \'test\''), strtotime('2023-01-02 03:04:05 UTC'), 'legacy datetimes are converted to epoch seconds');
t_is(t_one('select `clubs` from `activities` where `id` = 1'), '["test"]', 'legacy activities keep their club through the cid backfill');
t_is((int)t_one('select count(*) from `followers`'), 1, 'the merge keeps existing followers');

t_group('schema / resume after an interrupted merge');

// 崩在 DDL 之后、写版本号之前是最坏的一种：结构已经是新的，meta 还停在旧版本，重启后那一步会从头再跑一遍。
// 每一步都要能在「已经做完」的库上重跑，而且不能把已经合并好的部分改坏
for ($version = 1; $version <= DB_VERSION; $version++) {
    Club_DB_Version($version - 1);
    list($result, $error) = t_schema_merge();
    t_is($result, DB_VERSION, 'a merge restarted at version '.($version - 1).' finishes'.$error);
    t_is(t_schema_diff($merged, t_schema_dump()), [], 'a merge restarted at version '.($version - 1).' changes nothing');
}

t_group('schema / second run');

list($version, $error) = t_schema_merge();
t_is($version, DB_VERSION, 'a second merge is a no-op'.$error);
t_is(t_schema_diff($merged, t_schema_dump()), [], 'a second merge changes nothing');
// checkpoint 的生命周期不能超出它那一版，留下来的话下一次合并会读到上一次的半截状态
t_is((int)t_one('select count(*) from `meta` where `name` like \'migration.%\''), 0, 'no migration checkpoint outlives its own version');

// 回滚或滚动部署中的旧代码不认识新结构，继续写会损坏数据
Club_DB_Version(DB_VERSION + 1);
list($version, $error) = t_schema_merge();
t_is($version, null, 'a database newer than the code is refused outright');
t_is(t_schema_diff($merged, t_schema_dump()), [], 'a refused merge changes nothing');
Club_DB_Version(DB_VERSION);
