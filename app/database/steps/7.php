<?php

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
