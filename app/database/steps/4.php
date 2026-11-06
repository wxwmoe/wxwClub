<?php

function Club_Migrate_4() {
    if (($info = Club_Schema_Column('activities', 'polled')) === false) Club_Migrate_Exec('activities add polled', 'alter table `activities` add `polled` int not null default 0 after `updated`');
    elseif ($info['type'] !== 'int' || $info['nullable'] || (string)$info['default'] !== '0')
        Club_Migrate_Exec('activities modify polled', 'alter table `activities` modify `polled` int not null default 0');
}

function Club_Migrate_4_Validate() {
    $info = Club_Migrate_Assert_Column('activities', 'polled', 'int', null, false);
    Club_Migrate_Assert((string)$info['default'] === '0', 'activities.polled defaults to zero', ['actual' => $info['default']]);
}
