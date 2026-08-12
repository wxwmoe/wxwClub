<?php

/* 调度状态真正落到库里那一半：租约的互斥和 fencing、投递结果写成什么行、黑名单的进出、对账和回收。
 * 决策逻辑本身在 tests/groups/pure.php 里表驱动地过一遍，这里只问一件事 —— 决策照原样写进去了没有，以及并发下的那几条边界（陈旧 token、领取互斥）拦不拦得住。 */

$t_url = 'https://remote.example/inbox';

// 每个用例一份干净的库，前一个用例留下的行会让计数全都对不上
function t_state_reset() {
    t_db_import();
    t_exec('insert into `clubs`(`name`,`public_key`,`private_key`,`timestamp`) values (\'test\', \'\', \'\', :now)', [':now' => time()]);
}

function t_state_pending($url, $category, $due = null, $retries = 0) {
    $now = time();
    t_exec('insert into `tasks`(`cid`,`type`,`jsonld`,`timestamp`) select `cid`, :category, \'{}\', :now from `clubs` where `name` = \'test\'', [':category' => $category, ':now' => $now]);
    $task = (int)t_one('select max(`tid`) from `tasks`');
    t_exec('insert into `queues`(`tid`,`type`,`target`,`due_at`,`retries`) values (:tid, :type, :url, :due, :retries)',
        [':tid' => $task, ':type' => $category, ':url' => $url, ':due' => isset($due) ? $due : $now, ':retries' => $retries]);
    Club_Endpoint_Upsert($task, $category);
    return (int)t_one('select max(`id`) from `queues`');
}

// 一条 endpoint 加它名下的一条 queue，返回 queue 的 id
function t_state_queue($url, $endpoint = [], $due = null, $retries = 0, $category = 'relay') {
    $now = time();
    $endpoint = array_merge(['fails' => 0, 'fail_since' => 0, 'retry_at' => 0, 'next_at' => $now, 'follow_at' => null, 'notice_at' => null, 'announce_at' => null, 'relay_at' => null,
        'idle_since' => 0, 'lease_until' => 0], $endpoint);
    $endpoint[$category.'_at'] = $endpoint['next_at'];
    $id = t_state_pending($url, $category, $due, $retries);
    t_exec('insert into `endpoints`(`url`,`fails`,`fail_since`,`retry_at`,`next_at`,`follow_at`,`notice_at`,`announce_at`,`relay_at`,`idle_since`,`lease_until`)'.
        ' values (:url, :fails, :since, :retry, :next, :follow, :notice, :announce, :relay, :idle, :lease) on duplicate key update `fails` = :fails2, `fail_since` = :since2,'.
        ' `retry_at` = :retry2, `next_at` = :next2, `follow_at` = :follow2, `notice_at` = :notice2, `announce_at` = :announce2, `relay_at` = :relay2, `idle_since` = :idle2, `lease_until` = :lease2',
        [':url' => $url, ':fails' => $endpoint['fails'], ':since' => $endpoint['fail_since'], ':retry' => $endpoint['retry_at'], ':next' => $endpoint['next_at'],
            ':follow' => $endpoint['follow_at'], ':notice' => $endpoint['notice_at'], ':announce' => $endpoint['announce_at'], ':relay' => $endpoint['relay_at'],
            ':idle' => $endpoint['idle_since'], ':lease' => $endpoint['lease_until'], ':fails2' => $endpoint['fails'], ':since2' => $endpoint['fail_since'], ':retry2' => $endpoint['retry_at'],
            ':next2' => $endpoint['next_at'], ':follow2' => $endpoint['follow_at'], ':notice2' => $endpoint['notice_at'], ':announce2' => $endpoint['announce_at'], ':relay2' => $endpoint['relay_at'],
            ':idle2' => $endpoint['idle_since'], ':lease2' => $endpoint['lease_until']]);
    return $id;
}

function t_state_lease($url, $lease = 120) {
    $token = Club_Lease_Token();
    t_exec('update `endpoints` set `lease_token` = unhex(:token), `lease_until` = :until where `url` = :url', [':url' => $url, ':token' => $token, ':until' => time() + $lease]);
    return $token;
}

function t_state_endpoint($url) {
    return t_row('select `fails`, `fail_since`, `retry_at`, `next_at`, `follow_at`, `notice_at`, `announce_at`, `relay_at`, `idle_since`, `lease_until`, `lease_token`'.
        ' from `endpoints` where `url` = :url', [':url' => $url]);
}

t_group('state / delivery results');

$now = time();

// 投成功：这一行没了，故障段清零，最后一条 queue 走掉之后 next_at 变空、idle_since 起算
t_state_reset();
$id = t_state_queue($t_url, ['fails' => 3, 'fail_since' => $now - 500, 'retry_at' => $now - 10]);
$token = t_state_lease($t_url);
t_is(Club_Endpoint_Result($t_url, $token, ['id' => $id, 'club' => 'test'], 'ok'), true, 'ok is accepted');
t_is((int)t_one('select count(*) from `queues`'), 0, 'ok removes the queue row');
$row = t_state_endpoint($t_url);
t_is((int)$row['fails'], 0, 'ok clears fails in the database');
t_is((int)$row['fail_since'], 0, 'ok clears fail_since in the database');
t_is((int)$row['retry_at'], 0, 'ok clears retry_at in the database');
t_is($row['next_at'], null, 'an endpoint with no queues left is unscheduled');
t_ok((int)$row['idle_since'] > 0, 'an endpoint that went empty starts its idle clock');
t_is($row['lease_token'], null, 'completion releases the lease');

// 失败：整条 endpoint 退避，这一行跟着推到解禁之后
t_state_reset();
$id = t_state_queue($t_url);
$token = t_state_lease($t_url);
Club_Endpoint_Result($t_url, $token, ['id' => $id, 'club' => 'test'], 'failed');
$row = t_state_endpoint($t_url);
$queue = t_row('select `due_at`, `retries` from `queues` where `id` = :id', [':id' => $id]);
t_is((int)$row['fails'], 1, 'a failure counts on the endpoint');
t_ok((int)$row['retry_at'] >= $now + 60, 'a failure backs the endpoint off');
t_is((int)$queue['retries'], 1, 'a failure charges the row once');
t_is((int)$queue['due_at'], (int)$row['retry_at'], 'the row is pushed past the backoff, not just the endpoint');
t_is((int)$row['next_at'], (int)$row['retry_at'], 'next_at follows the backoff');
t_is((int)$row['idle_since'], 0, 'an endpoint that still has work is not idle');

// 挂了一周、采样也够：整条判死刑，backlog 留给维护队列分批清
t_state_reset();
$id = t_state_queue($t_url, ['fails' => 6, 'fail_since' => $now - 604801]);
$token = t_state_lease($t_url);
Club_Endpoint_Result($t_url, $token, ['id' => $id, 'club' => 'test'], 'failed');
t_is((int)t_one('select count(*) from `blacklist` where `target` = :url', [':url' => $t_url]), 1, 'a week of failures blacklists the target');
t_is((int)t_one('select count(*) from `queues`'), 1, 'blacklisting leaves the backlog in place');
t_is(t_state_endpoint($t_url)['next_at'], null, 'a blacklisted endpoint is unscheduled');

// 就这一条过不去：按行放弃，不牵连这家的其他投递
t_state_reset();
$id = t_state_queue($t_url, [], null, 7);
$token = t_state_lease($t_url);
Club_Endpoint_Result($t_url, $token, ['id' => $id, 'club' => 'test'], 'failed');
t_is((int)t_one('select count(*) from `queues`'), 0, 'the eighth attempt drops the row');
t_is((int)t_one('select count(*) from `blacklist`'), 0, 'dropping a row never blacklists the target');
t_is((int)t_state_endpoint($t_url)['fails'], 1, 'the endpoint still records the failure');

// 本站 DNS 的问题不能记在对端头上
t_state_reset();
$id = t_state_queue($t_url, ['fails' => 2, 'fail_since' => $now - 100]);
$token = t_state_lease($t_url);
// 被测代码自己取一次 time()，跟这个文件开头那次差几秒，绝对值对不上是必然的，比区间
$started = time();
Club_Endpoint_Result($t_url, $token, ['id' => $id, 'club' => 'test'], 'local-dns');
$due = (int)t_one('select `due_at` from `queues` where `id` = :id', [':id' => $id]);
t_ok($due >= $started + 300 && $due <= time() + 300, 'local-dns defers the row five minutes');
t_is((int)t_one('select `retries` from `queues` where `id` = :id', [':id' => $id]), 0, 'local-dns does not charge the row');
t_is((int)t_state_endpoint($t_url)['fails'], 2, 'local-dns does not count against the target');

t_group('state / lease fencing');

// 领取是互斥的：同一条 endpoint 只放一次投递在途
t_state_reset();
$id = t_state_queue($t_url);
$claim = Club_Endpoint_Claim('relay', time());
t_ok(isset($claim['token']), 'an available endpoint can be claimed');
t_is(Club_Endpoint_Claim('relay', time()), null, 'a leased endpoint cannot be claimed again');

// 出网前换 token，旧 owner 醒过来一个字也写不进去
$next = Club_Lease_Token();
t_is(Club_Endpoint_Authorize($t_url, $claim['token'], $next, $id, 'relay'), true, 'the lease owner is authorized to go out');
t_is(Club_Endpoint_Authorize($t_url, $claim['token'], Club_Lease_Token(), $id, 'relay'), false, 'the superseded token is no longer authorized');
t_is(Club_Endpoint_Result($t_url, $claim['token'], ['id' => $id, 'club' => 'test'], 'ok'), false, 'a stale token cannot write a result');
t_is((int)t_one('select count(*) from `queues`'), 1, 'a stale result deletes nothing');
t_is(Club_Endpoint_Result($t_url, $next, ['id' => $id, 'club' => 'test'], 'ok'), true, 'the current token can write its result');

t_group('state / delivery categories');

// 四类都有到期任务时只放最高类领取；完成一类才轮到下一类，共享 token 仍让同一 endpoint 串行。
t_state_reset();
foreach (array_reverse(Club_Task_Category_List()) as $category) t_state_queue($t_url, [], null, 0, $category);
Club_Endpoint_Repair($t_url, time());
foreach (Club_Task_Category_List() as $position => $category) {
    foreach (array_slice(Club_Task_Category_List(), $position + 1) as $lower) t_is(Club_Endpoint_Claim($lower, time()), null, $lower.' waits behind due '.$category);
    $claim = Club_Endpoint_Claim($category, time());
    t_ok(isset($claim['token']), $category.' claims when every higher category is empty');
    $task = Club_Endpoint_Queue($t_url, $claim['token'], $category, time());
    t_is($task['type'] ?? null, $category, $category.' worker selects its own logical queue');
    t_is(Club_Endpoint_Result($t_url, $claim['token'], $task + ['club' => 'test'], 'ok'), true, $category.' completion advances the endpoint');
}

// 优先顺序只看已经到期的任务；未来的 follow 不能让当前可投的 relay 空等。
t_state_reset();
t_state_queue($t_url, [], null, 0, 'relay');
$future = time() + 3600;
t_state_queue($t_url, ['next_at' => $future], $future, 0, 'follow');
Club_Endpoint_Repair($t_url, time());
t_ok(Club_Endpoint_Claim('relay', time()) !== null, 'a future follow does not block a due relay');

// 低类领完之后才来的高类，要在选 queue 和最终出网两道窗口分别拦住。
t_state_reset();
$relay = t_state_queue($t_url);
$claim = Club_Endpoint_Claim('relay', time());
t_state_pending($t_url, 'follow');
t_is(Club_Endpoint_Queue($t_url, $claim['token'], 'relay', time()), null, 'a follow arriving after relay claim blocks queue selection');
Club_Endpoint_Release($t_url, $claim['token']);

t_state_reset();
$relay = t_state_queue($t_url);
$claim = Club_Endpoint_Claim('relay', time());
$task = Club_Endpoint_Queue($t_url, $claim['token'], 'relay', time());
t_state_pending($t_url, 'follow');
t_is(Club_Endpoint_Authorize($t_url, $claim['token'], Club_Lease_Token(), $task['id'], 'relay'), false, 'a follow arriving before authorization blocks relay output');
Club_Endpoint_Release($t_url, $claim['token']);

// 黑名单在出网前也拦一道，next_at 那个提示不算数
t_state_reset();
$id = t_state_queue($t_url);
$token = t_state_lease($t_url);
Club_Blacklist_Add($t_url, time());
t_is(Club_Endpoint_Authorize($t_url, $token, Club_Lease_Token(), $id, 'relay'), false, 'a blacklisted target is refused right before the request');
t_is(Club_Endpoint_Desired($t_url, 0), null, 'a blacklisted target has no desired schedule');

t_group('state / blacklist');

// 入队是黑名单真正生效的地方
t_state_reset();
Club_Blacklist_Add($t_url, time());
t_exec('insert into `tasks`(`cid`,`type`,`jsonld`,`timestamp`) select `cid`, \'push\', \'{}\', :now from `clubs` where `name` = \'test\'', [':now' => time()]);
t_is(Club_Queue_Insert((int)t_one('select max(`tid`) from `tasks`'), $t_url), null, 'a blacklisted target refuses new queue rows');
t_is((int)t_one('select count(*) from `queues`'), 0, 'nothing is written for a blacklisted target');

// 探活说还活着，但历史 backlog 还在：先只记状态，blacklist 行留着继续挡
t_state_reset();
$id = t_state_queue($t_url);
Club_Blacklist_Add($t_url, time());
t_exec('update `blacklist` set `check_at` = :now, `lease_token` = unhex(:token), `lease_until` = :until where `target` = :url',
    [':now' => time(), ':token' => $token = Club_Lease_Token(), ':until' => time() + 120, ':url' => $t_url]);
t_is(Club_Blacklist_Result($t_url, $token, true), 'pending', 'a live target with a backlog is only marked');
t_is((int)t_one('select count(*) from `blacklist` where `target` = :url', [':url' => $t_url]), 1, 'the blacklist row stays until the backlog is gone');
t_ok(t_one('select `restore_pending_at` from `blacklist` where `target` = :url', [':url' => $t_url]) !== null, 'recovery is recorded');

// backlog 清完了，控制行和黑名单一起走
t_exec('delete from `queues`');
t_is(Club_Blacklist_Cleanup(), 0, 'cleanup finds no rows left to delete');
t_is((int)t_one('select count(*) from `blacklist` where `target` = :url', [':url' => $t_url]), 0, 'an empty backlog releases the target');
t_is((int)t_one('select count(*) from `endpoints` where `url` = :url', [':url' => $t_url]), 0, 'the control row goes with it');

// 还是没人应：checks 涨，下一次探活拉开
t_state_reset();
Club_Blacklist_Add($t_url, time());
t_exec('update `blacklist` set `checks` = 2, `lease_token` = unhex(:token), `lease_until` = :until where `target` = :url',
    [':token' => $token = Club_Lease_Token(), ':until' => time() + 120, ':url' => $t_url]);
t_is(Club_Blacklist_Result($t_url, $token, false), 'dead', 'a silent target stays blacklisted');
t_is((int)t_one('select `checks` from `blacklist` where `target` = :url', [':url' => $t_url]), 3, 'a failed probe counts');
t_ok((int)t_one('select `check_at` from `blacklist` where `target` = :url', [':url' => $t_url]) >= time() + 86400 * 3, 'the next probe is pushed out');

// 陈旧 token 的探活结果同样一个字都写不进去
t_is(Club_Blacklist_Result($t_url, Club_Lease_Token(), true), '', 'a stale probe result is discarded');
t_is((int)t_one('select count(*) from `blacklist` where `target` = :url', [':url' => $t_url]), 1, 'a stale probe result restores nothing');

// 领取也是互斥的
t_exec('update `blacklist` set `check_at` = :now, `lease_token` = null, `lease_until` = 0 where `target` = :url', [':now' => time(), ':url' => $t_url]);
t_ok(Club_Blacklist_Claim(time()) !== null, 'a due blacklist row can be claimed');
t_is(Club_Blacklist_Claim(time()), null, 'a leased blacklist row cannot be claimed again');

t_group('state / reconcile and prune');

// next_at 损坏成未来时间：对账照 queues 扶正
t_state_reset();
$id = t_state_queue($t_url, ['next_at' => $now + 99999]);
t_is(Club_Endpoint_Repair($t_url, time()), true, 'a wrong next_at is repaired');
t_is((int)t_state_endpoint($t_url)['next_at'], (int)t_one('select `due_at` from `queues` where `id` = :id', [':id' => $id]), 'next_at is recomputed from queues');
t_is(Club_Endpoint_Repair($t_url, time()), false, 'a correct endpoint is left alone');

// 控制行整个丢了：补出来，但故障历史是补不回来的
t_exec('delete from `endpoints` where `url` = :url', [':url' => $t_url]);
t_is(Club_Endpoint_Repair($t_url, time()), true, 'a missing control row is rebuilt');
t_is((int)t_one('select count(*) from `endpoints` where `url` = :url', [':url' => $t_url]), 1, 'the rebuilt row exists');

// 对账绝不能碰故障历史：那三列 queues 里恢复不出来
t_state_reset();
t_state_queue($t_url, ['fails' => 9, 'fail_since' => $now - 5000, 'retry_at' => $now + 600, 'next_at' => 1]);
Club_Endpoint_Repair($t_url, time());
$row = t_state_endpoint($t_url);
t_is((int)$row['fails'], 9, 'reconciliation keeps fails');
t_is((int)$row['fail_since'], $now - 5000, 'reconciliation keeps fail_since');
t_is((int)$row['retry_at'], $now + 600, 'reconciliation keeps retry_at');

// 空置够久才回收，宽限期内的留着 —— 一轮投递会让一个大群组名下几千行同时变空
t_state_reset();
t_exec('insert into `endpoints`(`url`,`next_at`,`idle_since`) values (:url, null, :idle)', [':url' => $t_url, ':idle' => $now - 604801]);
t_is(Club_Endpoint_Prune($t_url, time()), 'pruned', 'an endpoint idle past the grace period is removed');
t_exec('insert into `endpoints`(`url`,`next_at`,`idle_since`) values (:url, null, :idle)', [':url' => $t_url, ':idle' => $now - 60]);
t_is(Club_Endpoint_Prune($t_url, time()), false, 'an endpoint inside the grace period is kept');
// 起算点缺失的无主行就地转成空闲态，否则永远等不到宽限期
t_exec('update `endpoints` set `idle_since` = 0, `next_at` = :next where `url` = :url', [':next' => $now + 99999, ':url' => $t_url]);
t_is(Club_Endpoint_Prune($t_url, time()), 'idled', 'a row with no idle clock gets one instead of being removed');
t_is(t_state_endpoint($t_url)['next_at'], null, 'idling also clears the stale schedule');

t_group('state / maintenance leader');

require_once(APP_ROOT.'/app/worker.php');

// 另一个 master 拿着锁的样子：另开一条连接占住它，落选的这个必须一个维护单元都不做
t_state_reset();
$t_rival = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'].';charset=utf8mb4',
    $config['mysql']['username'], $config['mysql']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// 锁名照抄一份而不是从被测代码里读：它是两个 master 之间的约定，改了名字就是选举失效，这几条断言正是要在那时候红
$t_lock = 'wxwclub_maintain:'.md5($config['mysql']['database']);
$t_take = $t_rival->prepare('select get_lock(:lock, 0)'); $t_take->execute([':lock' => $t_lock]);
t_is((int)$t_take->fetch(PDO::FETCH_COLUMN, 0), 1, 'a rival master can take the maintenance lock');
t_is(worker_maintain(time(), $config), false, 'a master that loses the lock runs no maintenance');
// 对面走了就得接手，否则整站的维护随着那个 master 一起没了
$t_free = $t_rival->prepare('select release_lock(:lock)'); $t_free->execute([':lock' => $t_lock]);
t_is(worker_maintain(time(), $config), true, 'the lock going free hands maintenance over');
