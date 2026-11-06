<?php

function Club_Migrate_3() {
    global $db;
    if (($info = Club_Schema_Column('endpoints', 'idle_since')) === false) Club_Migrate_Exec('endpoints add idle_since',
        'alter table `endpoints` add `idle_since` int unsigned not null default 0 after `next_at`');
    elseif ($info['type'] !== 'int unsigned' || $info['nullable'] || (string)$info['default'] !== '0')
        Club_Migrate_Exec('endpoints modify idle_since', 'alter table `endpoints` modify `idle_since` int unsigned not null default 0');
    Club_Migrate_Step('endpoints backfill idle_since', function () use ($db) {
        $pdo = $db->prepare('update `endpoints` set `idle_since` = :now where `next_at` is null and `idle_since` = 0');
        $pdo->execute([':now' => time()]);
        return $pdo->rowCount();
    });
    Club_Migrate_Exec('drop migration 2 state', 'delete from `meta` where `name` like \'migration.2.%\'');
}

function Club_Migrate_3_Validate() {
    global $db;
    $info = Club_Migrate_Assert_Column('endpoints', 'idle_since', 'int unsigned', null, false);
    Club_Migrate_Assert((string)$info['default'] === '0', 'endpoints.idle_since defaults to zero', ['actual' => $info['default']]);
    $pdo = $db->query('select count(*) from `endpoints` where `next_at` is null and `idle_since` = 0');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'idle endpoints carry an idle_since');
    $pdo = $db->query('select count(*) from `endpoints` where `next_at` is not null and `idle_since` > 0');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'scheduled endpoints carry no idle_since');
    $pdo = $db->query('select count(*) from `meta` where `name` like \'migration.2.%\'');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'migration 2 checkpoints were removed');
}
