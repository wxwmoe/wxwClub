<?php

/* endpoints 加四类最早到期提示，queues 带上 task.type 并按 target/type/due_at 建索引。type 冗余避免每投一行都 JOIN 并扫描同 endpoint 的整个 backlog；共享 lease_token 继续保证同一 inbox 只有一个请求在途。
 *
 * 存量 tasks.type 全是 push，按最低的 relay 消化，不为短期 backlog 重写 tasks。分类提示从 queues 分页重建，避免对百万行队列做一次无界 GROUP BY。 */

function Club_Migrate_5() {
    global $db;
    $pdo = $db->query('select count(*) from `tasks` where char_length(`type`) > 8');
    if ((int)$pdo->fetch(PDO::FETCH_COLUMN, 0)) throw new UnexpectedValueException('tasks.type contains a value longer than 8 characters');
    if (($info = Club_Schema_Column('tasks', 'type')) && $info['type'] !== 'varchar(8)') Club_Migrate_Exec('tasks narrow type',
        'alter table `tasks` modify `type` varchar(8) character set ascii collate ascii_general_ci not null');
    if (Club_Schema_Column('queues', 'type') === false) Club_Migrate_Exec('queues add type',
        'alter table `queues` add `type` varchar(8) character set ascii collate ascii_general_ci not null default \'relay\' after `tid`');
    Club_Migrate_AddKeys('queues', ['target_type_due' => '`target`,`type`,`due_at`,`id`']);
    Club_Migrate_DropKeys('queues', ['target_due']);
    foreach (['follow_at', 'notice_at', 'announce_at', 'relay_at'] as $column) if (Club_Schema_Column('endpoints', $column) === false)
        Club_Migrate_Exec('endpoints add '.$column, 'alter table `endpoints` add `'.$column.'` int unsigned default null after `next_at`');
    Club_Migrate_AddKeys('endpoints', [
        'follow_schedule' => '`follow_at`,`lease_until`',
        'notice_schedule' => '`notice_at`,`lease_until`',
        'announce_schedule' => '`announce_at`,`lease_until`',
        'relay_schedule' => '`relay_at`,`lease_until`'
    ]);

    $cursor = '';
    do {
        $pdo = $db->prepare('select `url`, `retry_at` from `endpoints` where `url` > :cursor order by `url` limit 200');
        $pdo->execute([':cursor' => $cursor]); $rows = $pdo->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cursor = $row['url']; $schedule = Club_Endpoint_Schedule($cursor, (int)$row['retry_at']);
            $pdo = $db->prepare('update `endpoints` set `next_at` = :next, `follow_at` = :follow, `notice_at` = :notice, `announce_at` = :announce, `relay_at` = :relay,'.
                ' `idle_since` = if(:next is null, if(`idle_since` > 0, `idle_since`, :now), 0) where `url` = :url');
            $pdo->execute([':url' => $cursor, ':next' => $schedule['next_at'], ':follow' => $schedule['follow_at'], ':notice' => $schedule['notice_at'],
                ':announce' => $schedule['announce_at'], ':relay' => $schedule['relay_at'], ':now' => time()]);
        }
    } while (count($rows) === 200);
}

function Club_Migrate_5_Validate() {
    Club_Migrate_Assert_Column('tasks', 'type', 'varchar(8)', 'ascii_general_ci', false);
    $info = Club_Migrate_Assert_Column('queues', 'type', 'varchar(8)', 'ascii_general_ci', false);
    Club_Migrate_Assert((string)$info['default'] === 'relay', 'queues.type defaults to relay', ['actual' => $info['default']]);
    Club_Migrate_Assert_Index('queues', 'target_type_due', false, ['target', 'type', 'due_at', 'id']);
    Club_Migrate_Assert(!Club_Schema_Index('queues', 'target_due'), 'queues.target_due was removed');
    foreach (['follow_at', 'notice_at', 'announce_at', 'relay_at'] as $column) Club_Migrate_Assert_Column('endpoints', $column, 'int unsigned', null, true);
    foreach (['follow', 'notice', 'announce', 'relay'] as $category) Club_Migrate_Assert_Index('endpoints', $category.'_schedule', false, [$category.'_at', 'lease_until']);
    global $db;
    $pdo = $db->query('select count(*) from `endpoints` where (`next_at` is null) <> (`follow_at` is null and `notice_at` is null and `announce_at` is null and `relay_at` is null)');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'endpoint aggregate and category schedules agree');
    $pdo = $db->query('select count(*) from `endpoints` where (`next_at` is null) <> (`idle_since` > 0)');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0), 'endpoint schedule and idle clock agree after category backfill');
}
