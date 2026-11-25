<?php

function Club_Migrate_8() {
    foreach (Club_Migrate_8_Pending() as $table => $definition) Club_Migrate_Exec($table.' identity collation', 'alter table `'.$table.'` modify '.$definition);
}

function Club_Migrate_8_Pending() {
    global $db; $pending = [];
    $engine = $db->prepare('select `engine` from `information_schema`.`tables` where `table_schema` = database() and `table_name` = :table');
    foreach (['users' => ['actor', 'ascii_general_ci', 'ascii'], 'activities' => ['object', 'utf8mb4_general_ci', 'utf8mb4'], 'notices' => ['object', 'ascii_general_ci', 'utf8mb4']] as $table => $spec) {
        list($column, $old, $charset) = $spec; $target = $charset.'_bin'; $nullable = $table === 'notices';
        $engine->execute([':table' => $table]); Club_Migrate_Assert($engine->fetchColumn() === 'InnoDB', $table.' engine');
        $info = Club_Migrate_Assert_Column($table, $column, 'varchar(255)', null, $nullable);
        Club_Migrate_Assert($info['default'] === null && $info['extra'] === '' && in_array($info['collation'], [$old, $target], true), $table.'.'.$column.' definition', $info);
        Club_Migrate_Assert_Index($table, $column, !$nullable, [$column]);
        Club_Migrate_Assert(!Club_Schema_Foreign_Info($table, $column) && !Club_Schema_Referenced_By($table, $column), $table.'.'.$column.' has no foreign key dependencies');
        if ($table !== 'users') Club_Migrate_Assert_Foreign($table, 'uid', 'users', 'uid');
        if ($info['collation'] !== $target) $pending[$table] = '`'.$column.'` varchar(255) character set '.$charset.' collate '.$target.($nullable ? ' default null' : ' not null');
    }
    return $pending;
}

function Club_Migrate_8_Validate() {
    Club_Migrate_Assert(!Club_Migrate_8_Pending(), 'identity columns use binary collations');
}
