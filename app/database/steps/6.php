<?php

/* users 加 webfinger：这一行的 handle 上次通过 WebFinger 回环校验的时刻，0 表示从没确认过。
 *
 * name 一直是拼出来的猜测值（preferredUsername 加 actor URL 的 host），从没跟对端核对过，host-meta 委托的部署里它就是错的。
 * 有了这一列才分得清「确认过的 handle」和「猜的」，也才敢拿 WebFinger 的答复当判据。存量行一律 0，由 CLI 逐步补齐，不在合并里回填 —— 那是一行一次出网请求。 */

function Club_Migrate_6() {
    if (Club_Schema_Column('users', 'webfinger') === false) Club_Migrate_Exec('users add webfinger',
        'alter table `users` add `webfinger` int not null default 0 after `refresh`');
}

function Club_Migrate_6_Validate() {
    $info = Club_Migrate_Assert_Column('users', 'webfinger', 'int', null, false);
    Club_Migrate_Assert((string)$info['default'] === '0', 'users.webfinger defaults to 0', ['actual' => $info['default']]);
}
