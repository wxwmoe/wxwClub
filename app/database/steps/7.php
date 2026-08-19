<?php

/* bans：人为封禁的 actor 和 host。clubs 为 NULL 是全站封禁，在验签之后的 inbox 检查点拦下整条活动；非空是一份群组名的 JSON 数组，只能在认出投稿目标之后拦，所以走 Club_Inbox_Create 的逐群组循环。
 *
 * 不复用 blacklist —— 那张表是投递健康度的状态机，被封的实例会被探活队列当成「挂掉的实例」定期敲门并自动解禁。停止投递靠删掉对方的关注关系，投递侧一个新概念都不用学。
 * 没有外键：必须能封一个本站还没见过的实例，挂上 users 就做不到了。群组存成一列而不是一 target 一行，是因为入站门口那次点查顺带就把作用域读回来了，逐群组判定不必再查一次库；
 * 存名字不存 cid，理由是导出的 CSV 要能喂给另一个站，而 cid 在那边什么都不是。照 activities.clubs 的先例，只是给得宽一些 —— 那边一条投稿点名七八个群组就到头了，这边是运维在划范围。 */

function Club_Migrate_7() {
    if (Club_Schema_Table('bans')) return;
    Club_Migrate_Exec('create bans', 'create table `bans` (`target` varchar(255) character set ascii collate ascii_bin not null,'.
        ' `type` varchar(8) character set ascii collate ascii_general_ci not null,'.
        ' `clubs` varchar(1024) character set ascii collate ascii_general_ci default null,'.
        ' `reason` varchar(255) character set utf8mb4 collate utf8mb4_general_ci default null,'.
        ' `timestamp` int not null, primary key (`target`)) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci');
}

function Club_Migrate_7_Validate() {
    Club_Migrate_Assert(Club_Schema_Table('bans'), 'bans exists');
    Club_Migrate_Assert_Column('bans', 'target', 'varchar(255)', 'ascii_bin', false);
    Club_Migrate_Assert_Column('bans', 'type', 'varchar(8)', 'ascii_general_ci', false);
    Club_Migrate_Assert_Column('bans', 'clubs', 'varchar(1024)', 'ascii_general_ci', true);
    Club_Migrate_Assert_Column('bans', 'reason', 'varchar(255)', 'utf8mb4_general_ci', true);
    Club_Migrate_Assert_Column('bans', 'timestamp', 'int', null, false);
    Club_Migrate_Assert_Index('bans', 'PRIMARY', true, ['target']);
}
