<?php

/* activities 加 polled：投票计票更新的版本号，取自活动 id 的 #updates/<poll.updated_at> 后缀。
 * 它和编辑的 updated 必须分开存：投票期内计票每几分钟就往前跳一次，共用一列会让它一路盖过 edited_at，之后原作者真的编辑这条投票帖，那包 Update 反而成了「更旧的包」被判重丢掉。
 *
 * 存量行没有转发过计票更新，默认 0 无需回填。 */

function Club_Migrate_4() {
    // 默认值是硬要求，Club_Update_Process 的 update 分支只认列里已有的值
    if (($info = Club_Schema_Column('activities', 'polled')) === false)
        Club_Migrate_Exec('activities add polled', 'alter table `activities` add `polled` int not null default 0 after `updated`');
    elseif ($info['type'] !== 'int' || $info['nullable'] || (string)$info['default'] !== '0')
        Club_Migrate_Exec('activities modify polled', 'alter table `activities` modify `polled` int not null default 0');
}

function Club_Migrate_4_Validate() {
    $info = Club_Migrate_Assert_Column('activities', 'polled', 'int', null, false);
    Club_Migrate_Assert((string)$info['default'] === '0', 'activities.polled defaults to zero',
        ['actual' => $info['default']]);
}
