<?php require_once(__DIR__.'/function.php');

// $maintain：rotate、过期清理这些是全站一份的活，跟领了哪条任务无关。
// 开多进程时每个进程都跑一遍只是把同样的事做 N 遍（rotate 要 glob 整个 logs/），
// 所以只交给 0 号进程，其余的专心投递
function worker($maintain = true) {
    global $db, $cycle, $config; $idle = 0; if (!isset($cycle)) $cycle = 0;
    // 两道按对端的闸门：熔断中的整家跳过，正在投递的一家也只放一条进去。
    // 后者靠 inuse 认在途 —— 熔断是失败落库之后才生效的，而一次投递要烧十几秒，
    // 这段时间里所有进程会把同一个死对端的行全部领走，各自白等一次超时。
    // 子查询必须带 distinct：不然 MySQL 会把它并进外层，报「不能对同一张表边改边查」。
    // 排序只认 timestamp，别往前面插列：插了的话 timestamp 用不上 pending 索引的
    // 范围过滤，每次领取都要扫遍所有退避中的行。重试行的 timestamp 已经推到未来，
    // 本来就排在后面，再按 retry 排一道是多余的
    $pdo = $db->prepare('update `queues` set `id` = last_insert_id(id), `inuse` = 1, `timestamp` = :timestamp where `inuse` = 0 and `timestamp` <= :timestamp and `host` not in (select `host` from `hosts` where `until` > :timestamp) and `host` not in (select `h` from (select distinct `host` as `h` from `queues` where `inuse` = 1) as `busy`) order by `timestamp` asc limit 1');
    $pdo->execute([':timestamp' => time()]);
    // 顺带把这家的 fails 带出来：投递成功时靠它判断要不要清熔断状态，
    // 失败时靠它判断这次该不该算在这一行头上，两处都不用再多查一次
    $pdo = $db->query('select q.id, c.name as club, t.tid, t.type, t.jsonld, q.target, q.host, q.retry, coalesce(h.fails, 0) as fails from `queues` as `q` left join `tasks` as `t` on q.tid = t.tid left join `clubs` as `c` on t.cid = c.cid left join `hosts` as `h` on q.host = h.host where `id` = last_insert_id() and row_count() <> 0');
    if ($task = $pdo->fetch(PDO::FETCH_ASSOC)) {
        // worker 是长期进程，关联标记不像 web 那样每请求自动归零，每条任务都要重设。
        // 用队列行号，同一条投递的入队、重试、成功几行就能串起来
        Club_Log_Ref('queue#'.$task['id']);
        switch ($task['type']) {
            case 'push':
                $pdo = $db->prepare('select count(*) from `blacklist` where `target` = :target');
                $pdo->execute([':target' => $task['target']]);
                if ($pdo->fetch(PDO::FETCH_COLUMN, 0)) {
                    // 入队之后目标才进的黑名单，出队时才发现
                    Club_Log_Event('debug', 'push dropped, target blacklisted',
                        ['club' => $task['club'], 'target' => $task['target']]);
                    $pdo = $db->prepare('delete from `queues` where `id` = :id');
                    $pdo->execute([':id' => $task['id']]);
                    $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                    $pdo->execute([':tid' => $task['tid']]);
                } elseif (($result = ActivityPub_POST($task['target'], $task['club'], $task['jsonld'])) == 'ok') {
                    // 投递成功是 debug：正常运行时每条投稿都会刷一行乘以关注实例数
                    Club_Log_Event('debug', 'push delivered', ['club' => $task['club'],
                        'target' => $task['target'], 'retry' => $task['retry']]);
                    // 这家之前挂着，现在活了：清掉熔断，它名下其余行下一轮就能领
                    Club_Host_Pass($task['host'], $task['fails']);
                    $pdo = $db->prepare('delete from `queues` where `id` = :id');
                    $pdo->execute([':id' => $task['id']]);
                    $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                    $pdo->execute([':tid' => $task['tid']]);
                } elseif ($result == 'local-dns') {
                    // 本站自己解析不动，什么都没证明：retry 和 fails 都不加，只把这行往后推。
                    // 记在对端头上的话，本站 DNS 挂几天就能把关注的实例全拉黑一遍
                    Club_Log_Event('debug', 'push deferred, waiting for local dns',
                        ['club' => $task['club'], 'target' => $task['target']]);
                    $pdo = $db->prepare('update `queues` set `inuse` = 0, `timestamp` = :timestamp where `id` = :id');
                    $pdo->execute([':id' => $task['id'], ':timestamp' => time() + 300]);
                } else {
                    // 失败先算到对端头上，退避和放弃都由它定
                    list($until, $drop, $fails) = Club_Host_Fail($task['host'], $result);
                    // retry 只认一种情况：这家好好的，就这一条发不出去。整体在挂时也计数的话，
                    // 一个只有一行的对端，那行会在几十分钟内爬到上限被丢掉，
                    // 而对端那套按天算的退避还没走完第一档。
                    // 判据要用刚落库的 fails 而不是领取时那份：领取发生在失败写进去之前，
                    // 一轮里第一条读到的永远是 0，照那个算等于每轮都给一行记一笔
                    $retry = $fails > 1 ? $task['retry'] : $task['retry'] + 1;
                    Club_Log_Event('debug', 'push failed, will retry', ['club' => $task['club'],
                        'target' => $task['target'], 'reason' => $result, 'retry' => $retry]);
                    if ($drop) {
                        // 这家判死刑，它在队列里的目标一次清干净，不用几千行各爬各的
                        Club_Host_Purge($task['host']);
                    } elseif ($retry >= 8) {
                        // 对端是好的，就这条过不去（多半是这条活动的 payload 让它 500），
                        // 按行放弃，不牵连这家的其他投递
                        Club_Log_Event('warning', 'push dropped after '.$retry.' failed attempts',
                            ['club' => $task['club'], 'target' => $task['target']]);
                        $pdo = $db->prepare('delete from `queues` where `id` = :id');
                        $pdo->execute([':id' => $task['id']]);
                        $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                        $pdo->execute([':tid' => $task['tid']]);
                    } else {
                        // 行的 timestamp 也要跟着推到熔断之后。只靠 until 拦的话，这几千行
                        // 一直是「到期可领」，堆在 pending 索引最前面，领取时每次都要跳一遍
                        $pdo = $db->prepare('update `queues` set `inuse` = 0, `retry` = :retry, `timestamp` = :timestamp where `id` = :id');
                        $pdo->execute([':id' => $task['id'], ':retry' => $retry, ':timestamp' => $until]);
                    }
                } break;
            default: break;
        } $cycle++;
        // 后面的 rotate、过期清理不属于这条任务，标记不清掉会挂到它头上
        Club_Log_Ref('');
    } else $idle = 1;
    if ($idle || $cycle > 9) {
        if ($maintain) Club_Log_Rotate($config['node']['log-retention'] ?? 30);
        // 长期进程要自己换天，rotate 也可能刚把当前这个文件清掉。
        // ini_set 的作用范围是本进程，这条不能跟着 $maintain 一起跳过
        Club_Log_Error_Path();
        if ($maintain) {
            // 会入队新任务，必须放在下面依赖 last_insert_id() 的语句之前
            Club_Notice_Expire($config['notice']['retention'] ?? 30);
            $pdo = $db->prepare('delete from `tasks` where `queues` < 1 and `timestamp` <= :timestamp');
            $pdo->execute([':timestamp' => time() - 30]);
            // 复位的是被 SIGKILL 带走的进程留下的行，30 秒的窗口比一次投递的超时长
            $pdo = $db->prepare('update `queues` set `inuse` = 0 where `inuse` = 1 and `timestamp` <= :timestamp');
            $pdo->execute([':timestamp' => time() - 30]);
            $pdo = $db->prepare('update `blacklist` set `inuse` = 0 where `inuse` = 1 and `timestamp` <= :timestamp');
            $pdo->execute([':timestamp' => time() - 30]);
            // 熔断早过期了、一天没再碰过、队列里也没它的行，这条就是纯粹的历史残留。
            // 删掉最多让它下次重解析一次 DNS，表的大小跟着活跃对端数走而不是一直涨
            $pdo = $db->prepare('delete from `hosts` where `until` <= :timestamp and `timestamp` <= :expire and `host` not in (select `host` from `queues`)');
            $pdo->execute([':timestamp' => time(), ':expire' => time() - 86400]);
        }
        // 探活也是一次 curl，慢起来一样堵进程，所以不归 0 号独占；
        // 领取语句本身是原子的，多进程各领各的
        $pdo = $db->prepare('update `blacklist` set `id` = last_insert_id(id), `inuse` = 1, `timestamp` = :timestamp where `inuse` = 0 and `timestamp` <= :timestamp order by `timestamp` asc limit 1');
        $pdo->execute([':timestamp' => time()]);
        $pdo = $db->query('select `id`, `retry`, `target` from `blacklist` where `id` = last_insert_id() and row_count() <> 0');
        if (($target = $pdo->fetch(PDO::FETCH_ASSOC)) && ($club = Club_System())) {
            if (ActivityPub_Alive($target['target'], $club)) {
                // 恢复投递跟停止投递一样是个大事件，两头都留一行才对得上
                Club_Log_Event('info', 'target removed from blacklist: '.$target['target'],
                    ['retry' => $target['retry']]);
                $pdo = $db->prepare('delete from `blacklist` where `id` = :id');
                $pdo->execute([':id' => $target['id']]);
            } else {
                $pdo = $db->prepare('update `blacklist` set `inuse` = 0, `retry` = :retry, `timestamp` = :timestamp where `id` = :id');
                $pdo->execute([':id' => $target['id'], ':retry' => $target['retry'] + 1, ':timestamp' => time() + 86400]);
            }
        } elseif ($idle) sleep(1); $cycle = 0;
    }
    if (($usage = memory_get_usage(1)) > 10 * 1024 * 1024) {
        global $stop; $stop = true;
        // 多进程模式下 master 会补一个回来，单进程则要靠容器重启
        Club_Log_Console('error', 'memory limit exceeded, stopping',
            ['bytes' => $usage, 'pid' => getmypid()]);
    }
}
