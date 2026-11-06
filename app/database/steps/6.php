<?php

function Club_Migrate_6() {
    if (Club_Schema_Column('users', 'webfinger') === false) Club_Migrate_Exec('users add webfinger',
        'alter table `users` add `webfinger` int not null default 0 after `refresh`');
}

function Club_Migrate_6_Validate() {
    $info = Club_Migrate_Assert_Column('users', 'webfinger', 'int', null, false);
    Club_Migrate_Assert((string)$info['default'] === '0', 'users.webfinger defaults to 0', ['actual' => $info['default']]);
}
