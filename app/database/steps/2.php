<?php

function Club_Migrate_2() {
    if (!Club_Schema_Table('endpoints')) Club_Migrate_Exec('create endpoints',
        'create table `endpoints` ('.
        '`url` varchar(255) character set ascii collate ascii_bin not null,'.
        '`fails` smallint unsigned not null default 0,'.
        '`fail_since` int unsigned not null default 0,'.
        '`retry_at` int unsigned not null default 0,'.
        '`next_at` int unsigned default null,'.
        '`lease_until` int unsigned not null default 0,'.
        '`lease_token` binary(16) default null,'.
        'primary key (`url`), key `schedule` (`next_at`,`lease_until`),'.
        'unique key `lease_token` (`lease_token`)'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    if (!Club_Schema_Table('dns')) Club_Migrate_Exec('create dns',
        'create table `dns` ('.
        '`host` varchar(255) character set ascii collate ascii_general_ci not null,'.
        '`ips` varchar(1024) character set ascii collate ascii_general_ci not null default \'\','.
        '`checked_at` int unsigned not null default 0,'.
        '`lock_until` int unsigned not null default 0,'.
        '`lock_token` binary(16) default null,'.
        'primary key (`host`), key `checked_at` (`checked_at`)'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');

    Club_Migrate_2_Queues();
    Club_Migrate_2_Blacklist();
    Club_Migrate_AddKeys('blacklist', ['lease_token' => 'unique `lease_token`', 'schedule' => '`restore_pending_at`,`check_at`,`lease_until`']);

    if (Club_Schema_Column('queues', 'host')) {
        Club_Migrate_DropKeys('queues', ['host']);
        Club_Migrate_Exec('queues drop host', 'alter table `queues` drop column `host`');
    }

    Club_Migrate_2_Normalize();
    Club_Migrate_AddKeys('queues', ['tid_target' => 'unique `tid`,`target`', 'target_due' => '`target`,`due_at`,`id`']);

    Club_Migrate_2_Endpoints();

    Club_Migrate_DropKeys('queues', ['pending', 'tid']);
    foreach (['timestamp', 'inuse', 'retry'] as $column) if (Club_Schema_Column('queues', $column)) Club_Migrate_Exec('queues drop '.$column, 'alter table `queues` drop column `'.$column.'`');
    if (Club_Schema_Column('blacklist', 'id')) {
        Club_Migrate_DropKeys('blacklist', ['pending']);
        $alter = [];
        $primary = Club_Schema_Index('blacklist', 'PRIMARY');
        if (!$primary || $primary['columns'] !== ['target']) {
            if ($primary) $alter[] = 'drop primary key';
            if (Club_Schema_Index('blacklist', 'target')) $alter[] = 'drop key `target`';
            $alter[] = 'add primary key (`target`)';
        }
        $alter[] = 'drop column `id`';
        foreach (['create', 'timestamp', 'inuse', 'retry'] as $column) if (Club_Schema_Column('blacklist', $column)) $alter[] = 'drop column `'.$column.'`';
        Club_Migrate_Exec('blacklist switch primary key', 'alter table `blacklist` '.implode(', ', $alter));
    }
    Club_Migrate_DropKeys('blacklist', ['pending', 'target']);
    foreach (['create', 'timestamp', 'inuse', 'retry'] as $column) if (Club_Schema_Column('blacklist', $column))
        Club_Migrate_Exec('blacklist drop '.$column, 'alter table `blacklist` drop column `'.$column.'`');
}

function Club_Migrate_2_Queues() {
    global $db;
    $added = false;
    if (!Club_Schema_Column('queues', 'due_at')) {
        Club_Migrate_Exec('queues add due_at', 'alter table `queues` add `due_at` int unsigned null after `target`');
        $added = true;
    }
    if (!Club_Schema_Column('queues', 'retries')) {
        Club_Migrate_Exec('queues add retries', 'alter table `queues` add `retries` tinyint unsigned null after `due_at`');
        $added = true;
    }

    if (Club_Migrate_State('migration.2.queues') !== 'ready' || $added) {
        if (Club_Schema_Column('queues', 'timestamp') && Club_Schema_Column('queues', 'retry')) {
            Club_Migrate_Negative('queues', ['timestamp', 'retry']);
            Club_Migrate_Exec('queues backfill due_at/retries', 'update `queues` set `due_at` = greatest(`timestamp`, 0), `retries` = least(greatest(`retry`, 0), 255)');
        }
    }
    $pdo = $db->query('select count(*) from `queues` where `due_at` is null or `retries` is null');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'queues scheduling columns are backfilled');
    $due = Club_Schema_Column('queues', 'due_at');
    if ($due['type'] !== 'int unsigned' || $due['nullable']) Club_Migrate_Exec('queues tighten due_at', 'alter table `queues` modify `due_at` int unsigned not null');
    $retries = Club_Schema_Column('queues', 'retries');
    if ($retries['type'] !== 'tinyint unsigned' || $retries['nullable'] || (string)$retries['default'] !== '0')
        Club_Migrate_Exec('queues tighten retries', 'alter table `queues` modify `retries` tinyint unsigned not null default 0');
    Club_Migrate_State('migration.2.queues', 'ready');
}

function Club_Migrate_2_Blacklist() {
    global $db;
    $added = false;
    $columns = [
        'created_at' => 'int unsigned null after `target`',
        'check_at' => 'int unsigned null after `created_at`',
        'checks' => 'smallint unsigned null after `check_at`',
        'restore_pending_at' => 'int unsigned default null after `checks`',
        'lease_until' => 'int unsigned not null default 0 after `restore_pending_at`',
        'lease_token' => 'binary(16) default null after `lease_until`',
    ];
    foreach ($columns as $column => $definition) if (!Club_Schema_Column('blacklist', $column)) {
        Club_Migrate_Exec('blacklist add '.$column, 'alter table `blacklist` add `'.$column.'` '.$definition);
        if (in_array($column, ['created_at', 'check_at', 'checks'], true)) $added = true;
    }

    if (Club_Migrate_State('migration.2.blacklist') !== 'ready' || $added) {
        if (Club_Schema_Column('blacklist', 'timestamp') && Club_Schema_Column('blacklist', 'retry')) {
            Club_Migrate_Negative('blacklist', ['timestamp', 'retry']);
            if (Club_Schema_Column('blacklist', 'create')) {
                $pdo = $db->query('select count(*) from `blacklist` where `create` is null');
                if ($rows = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0)) Club_Log_Console('warning', 'blacklist rows without a create time, using check time', ['rows' => $rows]);
                Club_Migrate_Exec('blacklist backfill', 'update `blacklist` set `created_at` = greatest(coalesce(`create`, `timestamp`, 0), 0),'.
                    ' `check_at` = greatest(`timestamp`, 0), `checks` = least(greatest(`retry`, 0), 65535)');
            }
        }
    }
    $pdo = $db->query('select count(*) from `blacklist` where `created_at` is null or `check_at` is null or `checks` is null');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'blacklist scheduling columns are backfilled');
    foreach (['created_at' => 'int unsigned', 'check_at' => 'int unsigned', 'checks' => 'smallint unsigned'] as $column => $type) {
        $info = Club_Schema_Column('blacklist', $column);
        if ($info['type'] !== $type || $info['nullable'] || ($column === 'checks' && (string)$info['default'] !== '0')) Club_Migrate_Exec('blacklist tighten '.$column,
            'alter table `blacklist` modify `'.$column.'` '.$type.' not null'.($column === 'checks' ? ' default 0' : ''));
    }
    Club_Migrate_State('migration.2.blacklist', 'ready');
}

function Club_Migrate_2_Validate() {
    global $db;
    foreach (['meta', 'queues', 'blacklist', 'endpoints', 'dns'] as $table) Club_Migrate_Assert(Club_Schema_Table($table), $table.' table exists');
    foreach (['timestamp', 'inuse', 'retry', 'host'] as $column) Club_Migrate_Assert(!Club_Schema_Column('queues', $column), 'queues.'.$column.' was removed');
    Club_Migrate_Assert_Column('queues', 'target', 'varchar(255)', 'ascii_bin', false);
    Club_Migrate_Assert_Column('queues', 'due_at', 'int unsigned', null, false);
    $queueRetries = Club_Migrate_Assert_Column('queues', 'retries', 'tinyint unsigned', null, false);
    Club_Migrate_Assert((string)$queueRetries['default'] === '0', 'queues.retries default is zero');
    Club_Migrate_Assert_Index('queues', 'PRIMARY', true, ['id']);
    Club_Migrate_Assert_Index('queues', 'tid_target', true, ['tid', 'target']);
    Club_Migrate_Assert_Index('queues', 'target_due', false, ['target', 'due_at', 'id']);
    Club_Migrate_Assert_Foreign('queues', 'tid', 'tasks', 'tid');

    foreach (['id', 'create', 'timestamp', 'inuse', 'retry'] as $column) Club_Migrate_Assert(!Club_Schema_Column('blacklist', $column), 'blacklist.'.$column.' was removed');
    Club_Migrate_Assert_Column('blacklist', 'target', 'varchar(255)', 'ascii_bin', false);
    Club_Migrate_Assert_Column('blacklist', 'created_at', 'int unsigned', null, false);
    Club_Migrate_Assert_Column('blacklist', 'check_at', 'int unsigned', null, false);
    $blacklistChecks = Club_Migrate_Assert_Column('blacklist', 'checks', 'smallint unsigned', null, false);
    Club_Migrate_Assert((string)$blacklistChecks['default'] === '0', 'blacklist.checks default is zero');
    Club_Migrate_Assert_Column('blacklist', 'restore_pending_at', 'int unsigned', null, true);
    $blacklistLease = Club_Migrate_Assert_Column('blacklist', 'lease_until', 'int unsigned', null, false);
    Club_Migrate_Assert((string)$blacklistLease['default'] === '0', 'blacklist.lease_until default is zero');
    Club_Migrate_Assert_Column('blacklist', 'lease_token', 'binary(16)', null, true);
    Club_Migrate_Assert_Index('blacklist', 'PRIMARY', true, ['target']);
    Club_Migrate_Assert_Index('blacklist', 'lease_token', true, ['lease_token']);
    Club_Migrate_Assert_Index('blacklist', 'schedule', false, ['restore_pending_at', 'check_at', 'lease_until']);

    Club_Migrate_Assert_Column('endpoints', 'url', 'varchar(255)', 'ascii_bin', false);
    foreach (['fails' => 'smallint unsigned', 'fail_since' => 'int unsigned', 'retry_at' => 'int unsigned', 'lease_until' => 'int unsigned'] as $column => $type) {
        $info = Club_Migrate_Assert_Column('endpoints', $column, $type, null, false);
        Club_Migrate_Assert((string)$info['default'] === '0', 'endpoints.'.$column.' default is zero');
    }
    Club_Migrate_Assert_Column('endpoints', 'next_at', 'int unsigned', null, true);
    Club_Migrate_Assert_Column('endpoints', 'lease_token', 'binary(16)', null, true);
    Club_Migrate_Assert_Index('endpoints', 'PRIMARY', true, ['url']);
    Club_Migrate_Assert_Index('endpoints', 'schedule', false, ['next_at', 'lease_until']);
    Club_Migrate_Assert_Index('endpoints', 'lease_token', true, ['lease_token']);
    Club_Migrate_Assert_Column('dns', 'host', 'varchar(255)', 'ascii_general_ci', false);
    $dnsIps = Club_Migrate_Assert_Column('dns', 'ips', 'varchar(1024)', 'ascii_general_ci', false);
    Club_Migrate_Assert((string)$dnsIps['default'] === '', 'dns.ips default is empty');
    foreach (['checked_at', 'lock_until'] as $column) {
        $info = Club_Migrate_Assert_Column('dns', $column, 'int unsigned', null, false);
        Club_Migrate_Assert((string)$info['default'] === '0', 'dns.'.$column.' default is zero');
    }
    Club_Migrate_Assert_Column('dns', 'lock_token', 'binary(16)', null, true);
    Club_Migrate_Assert_Index('dns', 'PRIMARY', true, ['host']);
    Club_Migrate_Assert_Index('dns', 'checked_at', false, ['checked_at']);

    foreach ([['users', 'inbox'], ['users', 'shared_inbox'], ['queues', 'target'], ['blacklist', 'target']] as $column) {
        Club_Migrate_Assert_Column($column[0], $column[1], 'varchar(255)', 'ascii_bin', false);
        Club_Migrate_2_URL_Assert_Canonical($column[0], $column[1], $column[0].'.'.$column[1].' URL is canonical');
    }
    $pdo = $db->query('select count(*) from (select `target` from `queues` union select `target` from `blacklist`) `t` left join `endpoints` `e` on `t`.`target` = `e`.`url` where `e`.`url` is null');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'all delivery targets have endpoint rows');
    $pdo = $db->query('select count(*) from `blacklist` `b` join `endpoints` `e` on `b`.`target` = `e`.`url` where `e`.`next_at` is not null');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'blacklisted endpoints are unscheduled');
    Club_Migrate_Assert(!Club_Schema_Table('hosts'), 'hosts table was removed');
    foreach (['migrate_urls', 'migrate_queues', 'migrate_blacklist', 'migrate_variants'] as $table) Club_Migrate_Assert(!Club_Schema_Table($table), $table.' work table was removed');
    Club_Migrate_Assert(Club_Migrate_State('migration.2.queues') === 'ready', 'queues migration state is ready');
    Club_Migrate_Assert(Club_Migrate_State('migration.2.blacklist') === 'ready', 'blacklist migration state is ready');
    Club_Migrate_Assert(Club_Migrate_State('migration.2.urls') === 'done', 'URL migration state is done');
    return true;
}

function Club_Migrate_2_Endpoints() {
    global $db;
    if (Club_Schema_Table('hosts')) {
        Club_Migrate_Exec('dns backfill', 'insert ignore into `dns`(`host`,`ips`,`checked_at`) select `host`, `ips`, greatest(`resolved`, 0) from `hosts`');
        Club_Migrate_Exec('endpoints backfill',
            'insert ignore into `endpoints`(`url`,`fails`,`fail_since`,`retry_at`,`next_at`) select `t`.`target`, greatest(coalesce(`h`.`fails`, 0), 0),'.
            ' greatest(coalesce(`h`.`since`, 0), 0), greatest(coalesce(`h`.`until`, 0), 0), if(`b`.`target` is not null, null, greatest(greatest(coalesce(`h`.`until`, 0), 0),'.
            ' coalesce((select min(`q`.`due_at`) from `queues` `q` where `q`.`target` = `t`.`target`), 0))) from (select `target` from `queues` union select `target` from `blacklist`) as `t`'.
            ' left join `blacklist` `b` on `b`.`target` = `t`.`target` left join `hosts` `h` on `h`.`host` = if( substring_index(substring_index(`t`.`target`, \'/\', 3), \'/\', -1) like \'[%\','.
            ' substring_index(substring_index(substring_index(substring_index(`t`.`target`, \'/\', 3), \'/\', -1), \']\', 1), \'[\', -1),'.
            ' substring_index(substring_index(substring_index(`t`.`target`, \'/\', 3), \'/\', -1), \':\', 1)) collate ascii_general_ci');
        Club_Migrate_Exec('drop hosts', 'drop table `hosts`');
    }
    $pdo = $db->query('select count(*) from `queues` `q` left join `endpoints` `e` on `q`.`target` = `e`.`url` where `e`.`url` is null');
    if ((int)$pdo->fetch(PDO::FETCH_COLUMN, 0)) Club_Migrate_Exec('endpoints from queues', 'insert ignore into `endpoints`(`url`,`next_at`)'.
        ' select `q`.`target`, min(`q`.`due_at`) from `queues` `q` left join `blacklist` `b` on `q`.`target` = `b`.`target` where `b`.`target` is null group by `q`.`target`');
    $pdo = $db->query('select count(*) from `blacklist` `b` left join `endpoints` `e` on `b`.`target` = `e`.`url` where `e`.`url` is null');
    if ((int)$pdo->fetch(PDO::FETCH_COLUMN, 0)) Club_Migrate_Exec('endpoints from blacklist', 'insert ignore into `endpoints`(`url`,`next_at`) select `target`, null from `blacklist`');
    $pdo = $db->query('select count(*) from `blacklist` `b` join `endpoints` `e` on `b`.`target` = `e`.`url` where `e`.`next_at` is not null');
    if ((int)$pdo->fetch(PDO::FETCH_COLUMN, 0)) Club_Migrate_Exec('unschedule blacklisted endpoints',
        'update `endpoints` `e` join `blacklist` `b` on `e`.`url` = `b`.`target` set `e`.`next_at` = null');
    return true;
}

function Club_Migrate_2_URL_Pages($table, $column, $run, $limit = 500) {
    global $db;
    $after = null;
    for (;;) {
        $sql = 'select distinct `'.$column.'` collate ascii_bin as `url` from `'.$table.'`';
        if ($after !== null) $sql .= ' where `'.$column.'` collate ascii_bin > convert(unhex(:after) using ascii) collate ascii_bin';
        $sql .= ' order by `url` limit '.(int)$limit;
        $pdo = $db->prepare($sql);
        $pdo->execute($after === null ? [] : [':after' => bin2hex($after)]);
        $page = [];
        while (($url = $pdo->fetch(PDO::FETCH_COLUMN, 0)) !== false) $page[] = $url;
        if (!$page) break;
        $run($page);
        $last = $page[count($page) - 1];
        Club_Migrate_Assert($after === null || strcmp($last, $after) > 0, $table.'.'.$column.' URL cursor advances', ['after' => $after, 'last' => $last]);
        if (count($page) < $limit) break;
        $after = $last;
    }
    return true;
}

function Club_Migrate_2_URL_Assert_Canonical($table, $column, $check) {
    Club_Migrate_2_URL_Pages($table, $column, function ($urls) use ($table, $column, $check) {
        foreach ($urls as $url) {
            $canonical = Club_Endpoint_Normalize($url);
            Club_Migrate_Assert($canonical === false || $canonical === $url, $check, ['column' => $table.'.'.$column, 'url' => $url]);
        }
    });
    return true;
}

function Club_Migrate_2_Normalize() {
    global $db;
    $columns = [['users', 'inbox'], ['users', 'shared_inbox'], ['queues', 'target'], ['blacklist', 'target']];
    $state = Club_Migrate_State('migration.2.urls');
    if ($state === 'done') {
        foreach ($columns as $column) {
            $info = Club_Migrate_Assert_Column($column[0], $column[1]);
            if ($info['collation'] !== 'ascii_bin') Club_Migrate_Exec('collate '.$column[0].'.'.$column[1],
                'alter table `'.$column[0].'` modify `'.$column[1].'` varchar(255) character set ascii collate ascii_bin not null');
        }
        foreach (['migrate_urls', 'migrate_queues', 'migrate_blacklist', 'migrate_variants'] as $table) if (Club_Schema_Table($table)) Club_Migrate_Exec('drop '.$table, 'drop table `'.$table.'`');
        return false;
    }

    if ($state !== 'mapped') {
        $binary = false;
        foreach ($columns as $column) {
            $info = Club_Migrate_Assert_Column($column[0], $column[1]);
            if ($info['collation'] === 'ascii_bin') $binary = true;
        }
        if (!Club_Schema_Table('migrate_urls') || !$binary) Club_Migrate_2_URL_Map_Build($columns);
        else Club_Log_Console('info', 'url map resumed', ['urls' => (int)$db->query('select count(*) from `migrate_urls`')->fetch(PDO::FETCH_COLUMN, 0)]);
        Club_Migrate_2_URL_Map_Complete($columns);
        Club_Migrate_2_URL_Map_Identity();
        Club_Migrate_State('migration.2.urls', 'mapped');
    } elseif (!Club_Schema_Table('migrate_urls')) {
        Club_Migrate_2_URL_Map_Build($columns);
        Club_Migrate_2_URL_Map_Complete($columns);
        Club_Migrate_2_URL_Map_Identity();
    }

    foreach ($columns as $column) {
        $info = Club_Migrate_Assert_Column($column[0], $column[1]);
        if ($info['collation'] !== 'ascii_bin') Club_Migrate_Exec('collate '.$column[0].'.'.$column[1],
            'alter table `'.$column[0].'` modify `'.$column[1].'` varchar(255) character set ascii collate ascii_bin not null');
    }
    Club_Migrate_2_URL_Map_Complete($columns);
    Club_Migrate_2_URL_Map_Identity();
    Club_Migrate_2_Dedupe();
    Club_Migrate_Exec('users normalize inbox', 'update `users` `u` join `migrate_urls` `m` on `u`.`inbox` = `m`.`old_url` set `u`.`inbox` = `m`.`new_url`');
    Club_Migrate_Exec('users normalize shared_inbox', 'update `users` `u` join `migrate_urls` `m` on `u`.`shared_inbox` = `m`.`old_url` set `u`.`shared_inbox` = `m`.`new_url`');
    Club_Migrate_2_Variants();
    foreach ($columns as $column) Club_Migrate_2_URL_Assert_Canonical($column[0], $column[1], $column[0].'.'.$column[1].' URL normalization completed');
    Club_Migrate_State('migration.2.urls', 'done');
    foreach (['migrate_urls', 'migrate_queues', 'migrate_blacklist', 'migrate_variants'] as $table) if (Club_Schema_Table($table)) Club_Migrate_Exec('drop '.$table, 'drop table `'.$table.'`');
    return true;
}

function Club_Migrate_2_URL_Map_Build($columns) {
    global $db;
    if (Club_Schema_Table('migrate_urls')) Club_Migrate_Exec('reset migrate_urls', 'drop table `migrate_urls`');
    Club_Migrate_Exec('create migrate_urls', 'create table `migrate_urls` ('.
        '`old_url` varchar(255) character set ascii collate ascii_bin not null,'.
        '`new_url` varchar(255) character set ascii collate ascii_bin not null,'.
        'primary key (`old_url`), key `new_url` (`new_url`)'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    $invalid = 0;
    Club_Migrate_Step('populate migrate_urls', function () use ($columns, &$invalid) {
        foreach ($columns as $column) Club_Migrate_2_URL_Pages($column[0], $column[1], function ($urls) use ($column, &$invalid) {
            $result = Club_Migrate_2_URL_Map_Insert($urls, $column[0].'.'.$column[1], true); $invalid += $result['invalid']; });
    });
    $pdo = $db->query('select count(*) from (select `new_url` from `migrate_urls` group by `new_url` having count(*) > 1) as `c`');
    $collisions = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0);
    $urls = (int)$db->query('select count(*) from `migrate_urls`')->fetch(PDO::FETCH_COLUMN, 0);
    Club_Log_Console('info', 'url map built', ['urls' => $urls, 'invalid' => $invalid, 'collisions' => $collisions]);
    return $urls;
}

function Club_Migrate_2_URL_Map_Complete($columns) {
    global $db;
    Club_Migrate_Assert_Column('migrate_urls', 'old_url', 'varchar(255)', 'ascii_bin', false);
    Club_Migrate_Assert_Column('migrate_urls', 'new_url', 'varchar(255)', 'ascii_bin', false);
    Club_Migrate_Assert_Index('migrate_urls', 'PRIMARY', true, ['old_url']);
    Club_Migrate_Step('complete migrate_urls', function () use ($columns) {
        foreach ($columns as $column) Club_Migrate_2_URL_Pages($column[0], $column[1], function ($urls) use ($column) { Club_Migrate_2_URL_Map_Insert($urls, $column[0].'.'.$column[1], false); });
        Club_Migrate_2_URL_Map_Validate();
    });
    return (int)$db->query('select count(*) from `migrate_urls`')->fetch(PDO::FETCH_COLUMN, 0);
}

function Club_Migrate_2_URL_Map_Identity() {
    Club_Migrate_Step('map canonical URL identities', function () {
        Club_Migrate_2_URL_Pages('migrate_urls', 'new_url', function ($urls) { Club_Migrate_2_URL_Map_Insert($urls, 'migrate_urls.new_url', false); });
    });
    return true;
}

function Club_Migrate_2_URL_Map_Insert($urls, $column, $warn) {
    global $db;
    $values = []; $params = [];
    $invalid = 0;
    foreach ($urls as $url) {
        $canonical = Club_Endpoint_Normalize($url);
        if ($canonical === false) {
            $canonical = $url; $invalid++;
            if ($warn) Club_Log_Console('warning', 'url kept as is, cannot be normalized', ['column' => $column, 'url' => $url]);
        }
        $values[] = '(?, ?)'; $params[] = $url; $params[] = $canonical;
    }
    $inserted = 0;
    if ($values) {
        $pdo = $db->prepare('insert ignore into `migrate_urls`(`old_url`,`new_url`) values '.implode(',', $values));
        $pdo->execute($params); $inserted = $pdo->rowCount();
    }
    return ['rows' => count($urls), 'invalid' => $invalid, 'inserted' => $inserted];
}

function Club_Migrate_2_URL_Map_Validate($limit = 500) {
    global $db;
    $after = null;
    for (;;) {
        $sql = 'select `old_url`, `new_url` from `migrate_urls`';
        if ($after !== null) $sql .= ' where `old_url` > convert(unhex(:after) using ascii) collate ascii_bin';
        $sql .= ' order by `old_url` collate ascii_bin limit '.(int)$limit;
        $pdo = $db->prepare($sql);
        $pdo->execute($after === null ? [] : [':after' => bin2hex($after)]);
        $rows = 0; $last = null;
        while ($row = $pdo->fetch(PDO::FETCH_NUM)) {
            $canonical = Club_Endpoint_Normalize($row[0]);
            Club_Migrate_Assert($row[1] === ($canonical === false ? $row[0] : $canonical), 'URL map entry is canonical', ['old_url' => $row[0], 'new_url' => $row[1]]);
            $last = $row[0]; $rows++;
        }
        if (!$rows || $rows < $limit) break;
        Club_Migrate_Assert($after === null || strcmp($last, $after) > 0, 'URL map validation cursor advances', ['after' => $after, 'last' => $last]);
        $after = $last;
    }
    return true;
}

function Club_Migrate_2_Dedupe() {
    global $db;
    if (Club_Schema_Table('migrate_queues')) Club_Migrate_Exec('reset migrate_queues', 'drop table `migrate_queues`');
    Club_Migrate_Exec('create migrate_queues', 'create table `migrate_queues` ('.
        '`tid` int not null, `new_url` varchar(255) character set ascii collate ascii_bin not null,'.
        '`keep_id` int not null, `due_at` int unsigned not null, `retries` tinyint unsigned not null,'.
        'primary key (`tid`,`new_url`), unique key `keep_id` (`keep_id`)'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    Club_Migrate_Exec('build migrate_queues', 'insert into `migrate_queues`(`tid`,`new_url`,`keep_id`,`due_at`,`retries`) select `q`.`tid`, `m`.`new_url`, min(`q`.`id`),'.
        ' min(`q`.`due_at`), max(`q`.`retries`) from `queues` `q` join `migrate_urls` `m` on `q`.`target` = `m`.`old_url` group by `q`.`tid`, `m`.`new_url`');
    $pdo = $db->query('select (select count(*) from `queues`) - (select count(*) from `migrate_queues`)');
    Club_Log_Console('info', 'queues merged by canonical target', ['deleted' => (int)$pdo->fetch(PDO::FETCH_COLUMN, 0)]);
    Club_Migrate_Exec('queues merge scheduling state', 'update `queues` `q` join `migrate_queues` `k` on `q`.`id` = `k`.`keep_id` set `q`.`due_at` = `k`.`due_at`, `q`.`retries` = `k`.`retries`');
    Club_Migrate_Exec('queues drop duplicates', 'delete `q` from `queues` `q` left join `migrate_queues` `k` on `q`.`id` = `k`.`keep_id` where `k`.`keep_id` is null');
    Club_Migrate_Exec('queues normalize target', 'update `queues` `q` join `migrate_queues` `k` on `q`.`id` = `k`.`keep_id` set `q`.`target` = `k`.`new_url`');
    Club_Migrate_Exec('drop migrate_queues', 'drop table `migrate_queues`');

    if (Club_Schema_Table('migrate_blacklist')) Club_Migrate_Exec('reset migrate_blacklist', 'drop table `migrate_blacklist`');
    Club_Migrate_Exec('create migrate_blacklist', 'create table `migrate_blacklist` ('.
        '`new_url` varchar(255) character set ascii collate ascii_bin not null,'.
        '`keep_id` int not null, `created_at` int unsigned not null,'.
        '`check_at` int unsigned not null, `checks` smallint unsigned not null,'.
        'primary key (`new_url`), unique key `keep_id` (`keep_id`)'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    Club_Migrate_Exec('build migrate_blacklist', 'insert into `migrate_blacklist`(`new_url`,`keep_id`,`created_at`,`check_at`,`checks`) select `m`.`new_url`, min(`b`.`id`),'.
        ' min(`b`.`created_at`), max(`b`.`check_at`), max(`b`.`checks`) from `blacklist` `b` join `migrate_urls` `m` on `b`.`target` = `m`.`old_url` group by `m`.`new_url`');
    $pdo = $db->query('select (select count(*) from `blacklist`) - (select count(*) from `migrate_blacklist`)');
    Club_Log_Console('info', 'blacklist merged by canonical target', ['deleted' => (int)$pdo->fetch(PDO::FETCH_COLUMN, 0)]);
    Club_Migrate_Exec('blacklist merge scheduling state', 'update `blacklist` `b` join `migrate_blacklist` `k` on `b`.`id` = `k`.`keep_id`'.
        ' set `b`.`created_at` = `k`.`created_at`, `b`.`check_at` = `k`.`check_at`, `b`.`checks` = `k`.`checks`');
    Club_Migrate_Exec('blacklist drop duplicates', 'delete `b` from `blacklist` `b` left join `migrate_blacklist` `k` on `b`.`id` = `k`.`keep_id` where `k`.`keep_id` is null');
    Club_Migrate_Exec('blacklist normalize target', 'update `blacklist` `b` join `migrate_blacklist` `k` on `b`.`id` = `k`.`keep_id` set `b`.`target` = `k`.`new_url`');
    Club_Migrate_Exec('drop migrate_blacklist', 'drop table `migrate_blacklist`');
    return true;
}

function Club_Migrate_2_Variants() {
    global $db;
    if (Club_Schema_Table('migrate_variants')) Club_Migrate_Exec('reset migrate_variants', 'drop table `migrate_variants`');
    Club_Migrate_Exec('create migrate_variants', 'create table `migrate_variants` ('.
        '`target` varchar(255) character set ascii collate ascii_bin not null,'.
        '`created_at` int unsigned not null, `check_at` int unsigned not null,'.
        '`checks` smallint unsigned not null, primary key (`target`)'.
        ') engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
    Club_Migrate_Step('build migrate_variants', function () use ($db) {
        Club_Migrate_2_URL_Pages('migrate_urls', 'new_url', function ($urls) use ($db) {
            $marks = implode(',', array_fill(0, count($urls), '?'));
            $pdo = $db->prepare('insert ignore into `migrate_variants`(`target`,`created_at`,`check_at`,`checks`) select `m`.`new_url`, min(`b`.`created_at`), max(`b`.`check_at`), max(`b`.`checks`)'.
                ' from `migrate_urls` `m` join `blacklist` `b` on lower(`b`.`target`) collate ascii_bin = lower(`m`.`new_url`) collate ascii_bin'.
                ' left join `blacklist` `e` on `e`.`target` = `m`.`new_url` where `m`.`new_url` in ('.$marks.') and `e`.`target` is null group by `m`.`new_url`');
            $pdo->execute($urls);
        });
    });
    $rows = (int)$db->query('select count(*) from `migrate_variants`')->fetch(PDO::FETCH_COLUMN, 0);
    if ($rows) {
        Club_Migrate_Step('keep blacklist variants', function () use ($db) {
            Club_Migrate_2_URL_Pages('migrate_variants', 'target', function ($urls) use ($db) {
                foreach ($urls as $url) Club_Log_Console('warning', 'blacklist variant kept blocked', ['variant' => $url]);
                $marks = implode(',', array_fill(0, count($urls), '?'));
                $pdo = $db->prepare('insert ignore into `blacklist`(`target`,`created_at`,`check_at`,`checks`)'.
                    ' select `target`,`created_at`,`check_at`,`checks` from `migrate_variants` where `target` in ('.$marks.')');
                $pdo->execute($urls);
            });
        });
        Club_Log_Console('warning', 'blacklist case variants kept blocked', ['rows' => $rows]);
    }
    Club_Migrate_Exec('drop migrate_variants', 'drop table `migrate_variants`');
    return $rows;
}
