<?php

/* endpoints 加 idle_since：控制行变空的时刻。回收要等它空置够久才动手，否则大群组每投一轮就让名下几千行同时变空，几分钟后又原样建回来。
 *
 * 顺带清掉第 2 版留在 meta 里的断点。断点状态的生命周期不该超过它所属的那一版，但第 2 版已经在库里生效过，那个文件再也不会执行，这条只能补在这里。 */

function Club_Migrate_3() {
    global $db;
    // 定义不对就改过来：默认值是硬要求，Club_Endpoint_Upsert 的 insert 分支根本不写这一列，新排上的 endpoint 全靠它落成 0
    if (($info = Club_Schema_Column('endpoints', 'idle_since')) === false)
        Club_Migrate_Exec('endpoints add idle_since', 'alter table `endpoints`'.
            ' add `idle_since` int unsigned not null default 0 after `next_at`');
    elseif ($info['type'] !== 'int unsigned' || $info['nullable']
        || (string)$info['default'] !== '0')
        Club_Migrate_Exec('endpoints modify idle_since', 'alter table `endpoints`'.
            ' modify `idle_since` int unsigned not null default 0');
    // 存量的空行一条起点都没有，不回填就永远过不了宽限期，回收从此不工作。条件本身幂等：中途崩了重跑只会命中还没补过的那些
    Club_Migrate_Step('endpoints backfill idle_since', function () use ($db) {
        $pdo = $db->prepare('update `endpoints` set `idle_since` = :now'.
            ' where `next_at` is null and `idle_since` = 0');
        $pdo->execute([':now' => time()]);
        return $pdo->rowCount();
    });
    // 全新安装的库从来没有过这几行，所以只能是一条裸 DELETE，不能对影响行数下断言
    Club_Migrate_Exec('drop migration 2 state',
        'delete from `meta` where `name` like \'migration.2.%\'');
}

function Club_Migrate_3_Validate() {
    global $db;
    $info = Club_Migrate_Assert_Column('endpoints', 'idle_since', 'int unsigned', null, false);
    Club_Migrate_Assert((string)$info['default'] === '0', 'endpoints.idle_since defaults to zero',
        ['actual' => $info['default']]);
    // next_at 为空和 idle_since 非零必须同进同出：少了前者回收永远等不到宽限期，少了后者 endpoints_idle 会把在排队的行也算进去
    $pdo = $db->query('select count(*) from `endpoints`'.
        ' where `next_at` is null and `idle_since` = 0');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0),
        'idle endpoints carry an idle_since');
    $pdo = $db->query('select count(*) from `endpoints`'.
        ' where `next_at` is not null and `idle_since` > 0');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0),
        'scheduled endpoints carry no idle_since');
    $pdo = $db->query('select count(*) from `meta` where `name` like \'migration.2.%\'');
    Club_Migrate_Assert(!(int)$pdo->fetch(PDO::FETCH_COLUMN, 0),
        'migration 2 checkpoints were removed');
}
