<?php

/* 把任意历史结构补齐到调度重构之前的业务结构。
 *
 * 仓库最早的十几个版本没有任何升级记录，`tools/CHANGELOG.md` 是中途才有的，
 * 所以这一步不按「某次提交到某次提交」写，而是逐列逐索引地对齐目标形态：
 * 有就跳过，缺就补，类型不对就改。任何一个历史版本跑完都落到同一个结果。
 *
 * `hosts`、`queues.host`、`tasks.queues` 属于调度结构，第 2 版会删，这里不新建；
 * 已经存在的留给第 2 版处理。 */

function Club_Migrate_1() {
    Club_Migrate_1_Clubs();
    Club_Migrate_1_Users();
    Club_Migrate_1_Activities();
    Club_Migrate_1_Followers();
    Club_Migrate_1_Announces();
    Club_Migrate_1_Tasks();
    Club_Migrate_1_Queues();
    Club_Migrate_1_Blacklist();
    Club_Migrate_1_Notices();
}

function Club_Migrate_1_Validate() {
    foreach (['clubs', 'users', 'activities', 'followers', 'announces', 'tasks',
        'queues', 'blacklist', 'notices'] as $table)
        Club_Migrate_Assert(Club_Schema_Table($table), $table.' table exists');

    foreach (['id', 'tid', 'target', 'timestamp', 'inuse', 'retry'] as $column)
        Club_Migrate_Assert_Column('queues', $column);
    Club_Migrate_Assert_Column('queues', 'target', 'varchar(255)', 'ascii_general_ci', false);
    Club_Migrate_Assert_Index('queues', 'PRIMARY', true, ['id']);
    Club_Migrate_Assert_Index('queues', 'tid', false, ['tid']);
    Club_Migrate_Assert_Index('queues', 'pending', false, ['inuse', 'timestamp']);
    Club_Migrate_Assert_Foreign('queues', 'tid', 'tasks', 'tid');
    Club_Migrate_Assert_Foreign('activities', 'uid', 'users', 'uid');
    Club_Migrate_Assert_Foreign('followers', 'cid', 'clubs', 'cid');
    Club_Migrate_Assert_Foreign('followers', 'uid', 'users', 'uid');
    Club_Migrate_Assert_Foreign('tasks', 'cid', 'clubs', 'cid');

    foreach (['id', 'target', 'create', 'timestamp', 'inuse', 'retry'] as $column)
        Club_Migrate_Assert_Column('blacklist', $column);
    Club_Migrate_Assert_Column('blacklist', 'target', 'varchar(255)', 'ascii_general_ci', false);
    Club_Migrate_Assert_Column('blacklist', 'timestamp', 'int', null, false);
    Club_Migrate_Assert_Column('blacklist', 'inuse', 'tinyint', null, false);
    Club_Migrate_Assert_Column('blacklist', 'retry', 'smallint', null, false);
    $blacklistId = Club_Migrate_Assert_Column('blacklist', 'id', 'int', null, false);
    Club_Migrate_Assert(strpos($blacklistId['extra'], 'auto_increment') !== false,
        'blacklist.id is auto increment');
    Club_Migrate_Assert_Index('blacklist', 'PRIMARY', true, ['id']);
    Club_Migrate_Assert_Index('blacklist', 'target', true, ['target']);
    Club_Migrate_Assert_Index('blacklist', 'pending', false, ['inuse', 'timestamp']);

    Club_Migrate_Assert_Index('announces', 'cid_activity', true, ['cid', 'activity']);
    Club_Migrate_Assert_Index('announces', 'activity', false, ['activity']);
    Club_Migrate_Assert_Index('announces', 'cid_timestamp', false, ['cid', 'timestamp']);
    Club_Migrate_Assert_Index('announces', 'uid_timestamp', false, ['uid', 'timestamp']);
    Club_Migrate_Assert_Index('announces', 'timestamp_cid', false, ['timestamp', 'cid']);
    Club_Migrate_Assert_Foreign('announces', 'cid', 'clubs', 'cid');
    Club_Migrate_Assert_Foreign('announces', 'uid', 'users', 'uid');
    Club_Migrate_Assert_Foreign('announces', 'activity', 'activities', 'id');
    foreach (['id', 'uid', 'type', 'note', 'object', 'timestamp'] as $column)
        Club_Migrate_Assert_Column('notices', $column);
    $noticeId = Club_Migrate_Assert_Column('notices', 'id', 'int', null, false);
    Club_Migrate_Assert(strpos($noticeId['extra'], 'auto_increment') !== false,
        'notices.id is auto increment');
    Club_Migrate_Assert_Column('notices', 'uid', 'int', null, false);
    Club_Migrate_Assert_Column('notices', 'timestamp', 'int', null, false);
    Club_Migrate_Assert_Column('notices', 'type', 'varchar(20)', 'ascii_general_ci', false);
    Club_Migrate_Assert_Column('notices', 'note', 'varchar(255)', 'ascii_general_ci', true);
    Club_Migrate_Assert_Column('notices', 'object', 'varchar(255)', 'ascii_general_ci', true);
    Club_Migrate_Assert_Index('notices', 'PRIMARY', true, ['id']);
    Club_Migrate_Assert_Index('notices', 'uid_type_timestamp', false,
        ['uid', 'type', 'timestamp']);
    Club_Migrate_Assert_Index('notices', 'object', false, ['object']);
    Club_Migrate_Assert_Index('notices', 'timestamp', false, ['timestamp']);
    Club_Migrate_Assert_Foreign('notices', 'uid', 'users', 'uid');
    return true;
}

function Club_Migrate_1_Clubs() {
    // 早期版本把中英两个名称标签拆成两列，后来合并成一个标签到值的 JSON 对象
    if (Club_Schema_Column('clubs', 'infoname_cn')) {
        if (!Club_Schema_Column('clubs', 'infoname'))
            Club_Migrate_Exec('clubs add infoname', 'alter table `clubs`'.
                ' add `infoname` text character set utf8mb4 collate utf8mb4_general_ci after `nickname`');
        Club_Migrate_Exec('clubs merge infoname', 'update `clubs` set `infoname` ='.
            ' json_object(\':infoname_cn:\', coalesce(`infoname_cn`, `name`),'.
            ' \':infoname_en:\', coalesce(`infoname_en`, `name`))');
        Club_Migrate_Exec('clubs drop infoname_cn/en',
            'alter table `clubs` drop column `infoname_cn`, drop column `infoname_en`');
    }
    Club_Migrate_Datetime('clubs', 'create_time', 'timestamp');
    // 头像和横幅是外链，100 字节放不下带签名参数的对象存储 URL
    foreach (['avatar', 'banner'] as $column)
        if (($info = Club_Schema_Column('clubs', $column)) && $info['type'] !== 'varchar(255)')
            Club_Migrate_Exec('clubs widen '.$column, 'alter table `clubs` modify `'.$column.
                '` varchar(255) character set ascii collate ascii_general_ci default null');
    Club_Migrate_AddKeys('clubs', ['name' => 'unique `name`', 'timestamp' => '`timestamp`']);
}

function Club_Migrate_1_Users() {
    Club_Migrate_Datetime('users', 'create_time', 'timestamp');
    foreach (['actor', 'inbox', 'shared_inbox'] as $column)
        if (($info = Club_Schema_Column('users', $column)) && $info['type'] !== 'varchar(255)')
            Club_Migrate_Exec('users widen '.$column, 'alter table `users` modify `'.$column.
                '` varchar(255) character set ascii collate ascii_general_ci not null');
    if (!Club_Schema_Column('users', 'refresh'))
        Club_Migrate_Exec('users add refresh',
            'alter table `users` add `refresh` int not null default 0');
    // 唯一性从 name 挪到 actor：同一个 actor 只该有一行，而 name 是从 actor 推出来的
    // 展示名，不同实例的同名用户完全可能撞上。
    // 这里不自动去重：删一行 user 会顺着外键连它的关注、转发、提醒一起删掉，
    // 撞上了宁可让合并停在这里，先把冲突打印出来给人看
    if (!(($index = Club_Schema_Index('users', 'actor')) && $index['unique']))
        Club_Migrate_1_Conflicts('users', 'actor');
    Club_Migrate_AddKeys('users', ['actor' => 'unique `actor`', 'name' => '`name`']);
}

function Club_Migrate_1_Activities() {
    if (!Club_Schema_Table('activities') && Club_Schema_Table('activitys'))
        Club_Migrate_Exec('rename activitys', 'rename table `activitys` to `activities`');
    if (!Club_Schema_Table('activities')) return false;
    // 一条投稿可以同时进多个群组，单个 cid 表达不了。改存群组名的 JSON 列表，
    // 值能从原来的外键直接还原出来
    if (Club_Schema_Column('activities', 'cid')) {
        if (!Club_Schema_Column('activities', 'clubs'))
            Club_Migrate_Exec('activities add clubs', 'alter table `activities`'.
                ' add `clubs` varchar(255) character set ascii collate ascii_general_ci'.
                ' not null default \'\' after `type`');
        Club_Migrate_Exec('activities backfill clubs', 'update `activities` `a`'.
            ' join `clubs` `c` on `a`.`cid` = `c`.`cid` set `a`.`clubs` = json_array(`c`.`name`)');
        foreach (Club_Schema_Foreign('activities', 'cid') as $name)
            Club_Migrate_Exec('activities drop foreign key',
                'alter table `activities` drop foreign key `'.$name.'`');
        Club_Migrate_DropKeys('activities', ['cid']);
        Club_Migrate_Exec('activities drop cid', 'alter table `activities`'.
            ' drop column `cid`, modify `clubs` varchar(255) character set ascii'.
            ' collate ascii_general_ci not null');
    }
    // activity_id 当年是本地编号，现在一律用远端 object 的 URI 做唯一键
    if (Club_Schema_Column('activities', 'activity_id'))
        Club_Migrate_Exec('activities drop activity_id',
            'alter table `activities` drop column `activity_id`');
    Club_Migrate_Datetime('activities', 'create_time', 'timestamp');
    if (($info = Club_Schema_Column('activities', 'object')) && $info['type'] !== 'varchar(255)')
        Club_Migrate_Exec('activities widen object', 'alter table `activities`'.
            ' modify `object` varchar(255) collate utf8mb4_general_ci not null');
    if (($info = Club_Schema_Column('activities', 'clubs')) && $info['type'] !== 'varchar(255)')
        Club_Migrate_Exec('activities widen clubs', 'alter table `activities`'.
            ' modify `clubs` varchar(255) character set ascii collate ascii_general_ci not null');
    if (!Club_Schema_Column('activities', 'updated'))
        Club_Migrate_Exec('activities add updated', 'alter table `activities`'.
            ' add `updated` int not null default 0 after `object`');
    // 只有不存在下游引用的早期结构才能自动去重；否则必须由人决定保留哪一行
    if (!(($index = Club_Schema_Index('activities', 'object')) && $index['unique'])) {
        $conflicts = Club_Migrate_1_Conflicts('activities', 'object');
        $downstream = Club_Schema_Referenced_By('activities', 'id');
        if (Club_Schema_Table('announces') && Club_Schema_Column('announces', 'activity'))
            $downstream[] = ['table' => 'announces', 'column' => 'activity', 'name' => 'logical'];
        if ($conflicts && $downstream) Club_Migrate_Assert(false,
            'activities.object duplicates have downstream references', ['references' => $downstream]);
        if ($conflicts) Club_Migrate_1_Dedupe('activities', 'object');
    }
    Club_Migrate_AddKeys('activities', ['object' => 'unique `object`', 'uid' => '`uid`']);
}

// 建唯一键前按某一列去重，保留 id 最小的那行
function Club_Migrate_1_Dedupe($table, $column) {
    global $db;
    $where = ' where `id` not in (select `keep` from (select min(`id`) as `keep` from `'.$table.
        '` group by `'.$column.'`) as `k`)';
    $pdo = $db->query('select count(*) from `'.$table.'`'.$where);
    if (!($rows = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0))) return 0;
    Club_Log_Console('warning', 'duplicate rows removed before adding a unique key',
        ['table' => $table, 'column' => $column, 'rows' => $rows]);
    Club_Migrate_Exec($table.' dedupe '.$column, 'delete from `'.$table.'`'.$where);
    return $rows;
}

// 只报不删。撞上唯一键的合并会在下一句 ALTER 上失败，那条报错只说「Duplicate entry」，
// 不说是哪几行；先把它们打出来，人才知道要去修什么
function Club_Migrate_1_Conflicts($table, $column) {
    global $db;
    $rows = $db->query('select `'.$column.'`, count(*) as `rows` from `'.$table.
        '` group by `'.$column.'` having `rows` > 1 limit 50')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row)
        Club_Log_Console('error', 'duplicate value blocks a unique key',
            ['table' => $table, 'column' => $column, 'value' => $row[$column], 'rows' => $row['rows']]);
    return count($rows);
}

function Club_Migrate_1_Followers() {
    Club_Migrate_Datetime('followers', 'create_time', 'timestamp');
    // cid 单列索引被 cid_uid 完全覆盖，留着只是多一份写入开销
    Club_Migrate_DropKeys('followers', ['cid']);
    Club_Migrate_AddKeys('followers', ['cid_uid' => 'unique `cid`,`uid`', 'uid' => '`uid`']);
}

function Club_Migrate_1_Announces() {
    if (!Club_Schema_Table('announces')) Club_Migrate_Exec('create announces',
        'create table `announces` ('.
        '`id` int not null auto_increment, `cid` int not null, `uid` int not null,'.
        '`activity` int not null,'.
        '`summary` mediumtext collate utf8mb4_general_ci,'.
        '`content` mediumtext collate utf8mb4_general_ci not null,'.
        '`timestamp` int not null, primary key (`id`),'.
        'unique key `cid_activity` (`cid`,`activity`), key `activity` (`activity`),'.
        'key `cid_timestamp` (`cid`,`timestamp`), key `uid_timestamp` (`uid`,`timestamp`),'.
        'key `timestamp_cid` (`timestamp`,`cid`),'.
        'constraint `announces_ibfk_3` foreign key (`cid`) references `clubs` (`cid`)'.
        ' on delete cascade on update cascade,'.
        'constraint `announces_ibfk_5` foreign key (`uid`) references `users` (`uid`)'.
        ' on delete cascade on update cascade,'.
        'constraint `announces_ibfk_7` foreign key (`activity`) references `activities` (`id`)'.
        ' on delete cascade on update cascade'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    if (!Club_Schema_Column('announces', 'summary'))
        Club_Migrate_Exec('announces add summary', 'alter table `announces`'.
            ' add `summary` mediumtext collate utf8mb4_general_ci after `activity`');
    // text 上限 64KB，一条两万字的中文投稿加上 HTML 就能顶到
    foreach (['summary' => '', 'content' => ' not null'] as $column => $null)
        if (($info = Club_Schema_Column('announces', $column)) && $info['type'] !== 'mediumtext')
            Club_Migrate_Exec('announces widen '.$column, 'alter table `announces` modify `'.
                $column.'` mediumtext collate utf8mb4_general_ci'.$null);
    Club_Migrate_AddKeys('announces', ['cid_activity' => 'unique `cid`,`activity`',
        'activity' => '`activity`', 'cid_timestamp' => '`cid`,`timestamp`',
        'uid_timestamp' => '`uid`,`timestamp`', 'timestamp_cid' => '`timestamp`,`cid`']);
    // 外键列在删旧索引的整个过程中都必须有左前缀索引支撑
    Club_Migrate_DropKeys('announces', ['uid', 'timestamp']);
}

function Club_Migrate_1_Tasks() {
    if (!Club_Schema_Table('tasks')) Club_Migrate_Exec('create tasks',
        'create table `tasks` ('.
        '`tid` int not null auto_increment, `cid` int not null,'.
        '`type` varchar(10) character set ascii collate ascii_general_ci not null,'.
        '`jsonld` mediumtext character set utf8mb4 collate utf8mb4_general_ci not null,'.
        '`timestamp` int not null, primary key (`tid`), key `cid` (`cid`),'.
        'key `timestamp` (`timestamp`),'.
        'constraint `tasks_ibfk_2` foreign key (`cid`) references `clubs` (`cid`)'.
        ' on delete cascade on update cascade'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    if (($info = Club_Schema_Column('tasks', 'jsonld')) && $info['type'] !== 'mediumtext')
        Club_Migrate_Exec('tasks widen jsonld', 'alter table `tasks`'.
            ' modify `jsonld` mediumtext character set utf8mb4 collate utf8mb4_general_ci not null');
    // queues 是早先的在途计数，代码里已经没人读写，但它带着的复合索引让过期清理的
    // timestamp 过滤用不上索引，每次都要扫全表
    Club_Migrate_DropKeys('tasks', ['type', 'time', 'queues', 'queues_timestamp']);
    if (Club_Schema_Column('tasks', 'queues'))
        Club_Migrate_Exec('tasks drop queues counter', 'alter table `tasks` drop column `queues`');
    Club_Migrate_AddKeys('tasks', ['cid' => '`cid`', 'timestamp' => '`timestamp`']);
}

function Club_Migrate_1_Queues() {
    if (!Club_Schema_Table('queues')) Club_Migrate_Exec('create queues',
        'create table `queues` ('.
        '`id` int not null auto_increment, `tid` int not null,'.
        '`target` varchar(255) character set ascii collate ascii_general_ci not null,'.
        '`timestamp` int not null, `inuse` tinyint not null default 0,'.
        '`retry` tinyint not null default 0, primary key (`id`), key `tid` (`tid`),'.
        'key `pending` (`inuse`,`timestamp`),'.
        'constraint `queues_ibfk_2` foreign key (`tid`) references `tasks` (`tid`)'.
        ' on delete cascade on update cascade'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    if (($info = Club_Schema_Column('queues', 'target')) && $info['type'] !== 'varchar(255)')
        Club_Migrate_Exec('queues widen target', 'alter table `queues`'.
            ' modify `target` varchar(255) character set ascii collate ascii_general_ci not null');
    if (!Club_Schema_Column('queues', 'retry'))
        Club_Migrate_Exec('queues add retry',
            'alter table `queues` add `retry` tinyint not null default 0');
    // 历史索引集各版本都不一样，先收敛到一套，第 2 版才知道该拆掉什么
    Club_Migrate_DropKeys('queues', ['timestamp', 'inuse', 'retry']);
    Club_Migrate_AddKeys('queues', ['tid' => '`tid`', 'pending' => '`inuse`,`timestamp`']);
}

function Club_Migrate_1_Blacklist() {
    if (!Club_Schema_Table('blacklist')) Club_Migrate_Exec('create blacklist',
        'create table `blacklist` ('.
        '`id` int not null auto_increment,'.
        '`target` varchar(255) character set ascii collate ascii_general_ci not null,'.
        '`create` int default null, `timestamp` int not null default 0,'.
        '`inuse` tinyint not null default 0, `retry` smallint not null default 0,'.
        'primary key (`id`), unique key `target` (`target`),'.
        'key `pending` (`inuse`,`timestamp`)'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    if (!Club_Schema_Column('blacklist', 'id')) {
        $alter = [];
        if (Club_Schema_Index('blacklist', 'PRIMARY')) $alter[] = 'drop primary key';
        $alter[] = 'add `id` int not null auto_increment first';
        $alter[] = 'add primary key (`id`)';
        if (!Club_Schema_Index('blacklist', 'target'))
            $alter[] = 'add unique key `target` (`target`)';
        Club_Migrate_Exec('blacklist add row id',
            'alter table `blacklist` '.implode(', ', $alter));
    }
    $primary = Club_Schema_Index('blacklist', 'PRIMARY');
    if (!$primary || $primary['columns'] !== ['id']) {
        Club_Migrate_1_Conflicts('blacklist', 'id');
        $alter = [];
        if ($primary) $alter[] = 'drop primary key';
        $alter[] = 'add primary key (`id`)';
        if (!Club_Schema_Index('blacklist', 'target'))
            $alter[] = 'add unique key `target` (`target`)';
        Club_Migrate_Exec('blacklist use row id primary key',
            'alter table `blacklist` '.implode(', ', $alter));
    }
    $id = Club_Schema_Column('blacklist', 'id');
    if ($id['type'] !== 'int' || strpos($id['extra'], 'auto_increment') === false)
        Club_Migrate_Exec('blacklist fix row id', 'alter table `blacklist`'.
            ' modify `id` int not null auto_increment');
    if (!Club_Schema_Column('blacklist', 'create'))
        Club_Migrate_Exec('blacklist add create',
            'alter table `blacklist` add `create` int default null after `target`');
    if (!Club_Schema_Column('blacklist', 'timestamp'))
        Club_Migrate_Exec('blacklist add timestamp', 'alter table `blacklist`'.
            ' add `timestamp` int not null default 0 after `create`');
    $timestamp = Club_Schema_Column('blacklist', 'timestamp');
    if ($timestamp['nullable'])
        Club_Migrate_Exec('blacklist fill timestamp',
            'update `blacklist` set `timestamp` = 0 where `timestamp` is null');
    if ($timestamp['type'] !== 'int' || $timestamp['nullable'] ||
        (string)$timestamp['default'] !== '0')
        Club_Migrate_Exec('blacklist fix timestamp', 'alter table `blacklist`'.
            ' modify `timestamp` int not null default 0');
    if (!Club_Schema_Column('blacklist', 'inuse'))
        Club_Migrate_Exec('blacklist add inuse', 'alter table `blacklist`'.
            ' add `inuse` tinyint not null default 0 after `timestamp`');
    if (!Club_Schema_Column('blacklist', 'retry'))
        Club_Migrate_Exec('blacklist add retry', 'alter table `blacklist`'.
            ' add `retry` smallint not null default 0 after `inuse`');
    foreach (['inuse' => 'tinyint', 'retry' => 'smallint'] as $column => $type) {
        $info = Club_Schema_Column('blacklist', $column);
        if ($info['nullable']) Club_Migrate_Exec('blacklist fill '.$column,
            'update `blacklist` set `'.$column.'` = 0 where `'.$column.'` is null');
        if ($info['type'] !== $type || $info['nullable'] || (string)$info['default'] !== '0')
            Club_Migrate_Exec('blacklist fix '.$column, 'alter table `blacklist`'.
                ' modify `'.$column.'` '.$type.' not null default 0');
    }
    Club_Migrate_DropKeys('blacklist', ['timestamp', 'inuse']);
    Club_Migrate_AddKeys('blacklist',
        ['target' => 'unique `target`', 'pending' => '`inuse`,`timestamp`']);
}

function Club_Migrate_1_Notices() {
    if (!Club_Schema_Table('notices')) Club_Migrate_Exec('create notices',
        'create table `notices` ('.
        '`id` int not null auto_increment, `uid` int not null,'.
        '`type` varchar(20) character set ascii collate ascii_general_ci not null,'.
        '`note` varchar(255) character set ascii collate ascii_general_ci default null,'.
        '`object` varchar(255) character set ascii collate ascii_general_ci default null,'.
        '`timestamp` int not null, primary key (`id`),'.
        'key `uid_type_timestamp` (`uid`,`type`,`timestamp`), key `object` (`object`),'.
        'key `timestamp` (`timestamp`),'.
        'constraint `notices_ibfk_1` foreign key (`uid`) references `users` (`uid`)'.
        ' on delete cascade on update cascade'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    foreach (['id', 'uid', 'type', 'note', 'object', 'timestamp'] as $column)
        Club_Migrate_Assert_Column('notices', $column);
    foreach (['uid', 'timestamp'] as $column) {
        $info = Club_Schema_Column('notices', $column);
        if ($info['type'] !== 'int' || $info['nullable'])
            Club_Migrate_Exec('notices align '.$column, 'alter table `notices`'.
                ' modify `'.$column.'` int not null');
    }
    foreach (['type' => ['varchar(20)', false], 'note' => ['varchar(255)', true],
        'object' => ['varchar(255)', true]] as $column => $target) {
        $info = Club_Schema_Column('notices', $column);
        if ($info['type'] !== $target[0] || $info['collation'] !== 'ascii_general_ci' ||
            $info['nullable'] !== $target[1])
            Club_Migrate_Exec('notices align '.$column, 'alter table `notices` modify `'.$column.'` '.
                $target[0].' character set ascii collate ascii_general_ci'.
                ($target[1] ? ' default null' : ' not null'));
    }
    Club_Migrate_AddKeys('notices', ['uid_type_timestamp' => '`uid`,`type`,`timestamp`',
        'object' => '`object`', 'timestamp' => '`timestamp`']);
    Club_Migrate_Ensure_Foreign('notices', 'uid', 'notices_ibfk_1', 'users', 'uid');
    return true;
}
