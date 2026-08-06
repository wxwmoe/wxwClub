<?php require_once(__DIR__.'/function.php');

// $maintain：rotate、过期清理这些是全站一份的活，跟领了哪条任务无关。
// 开多进程时每个进程都跑一遍只是把同样的事做 N 遍（rotate 要 glob 整个 logs/），
// 所以只交给 0 号进程，其余的专心投递
function worker($maintain = true) {
    global $db, $cycle, $config; $idle = 0; if (!isset($cycle)) $cycle = 0;
    $pdo = $db->prepare('update `queues` set `id` = last_insert_id(id), `inuse` = 1, `timestamp` = :timestamp where `inuse` = 0 and `timestamp` <= :timestamp order by `retry`, `timestamp` asc limit 1');
    $pdo->execute([':timestamp' => time()]);
    $pdo = $db->query('select q.id, c.name as club, t.tid, t.type, t.jsonld, q.target, q.retry from `queues` as `q` left join `tasks` as `t` on q.tid = t.tid left join `clubs` as `c` on t.cid = c.cid where `id` = last_insert_id() and row_count() <> 0');
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
                } else {
                    if (($result = ActivityPub_POST($task['target'], $task['club'], $task['jsonld']))) {
                        // 投递成功是 debug：正常运行时每条投稿都会刷一行乘以关注实例数
                        Club_Log_Event('debug', 'push delivered', ['club' => $task['club'],
                            'target' => $task['target'], 'retry' => $task['retry']]);
                        $pdo = $db->prepare('delete from `queues` where `id` = :id');
                        $pdo->execute([':id' => $task['id']]);
                        $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                        $pdo->execute([':tid' => $task['tid']]);
                    } else {
                        $retry = $task['retry'] + 1;
                        // null 只在本站 DNS 整个坏掉时出现（对端自己注销域名走的是 false，
                        // 照常拉黑）。这种时候所有对端会一起失败，不卡住计数的话，
                        // 退避到 1 小时档以后连着挂 5 天就能把关注的实例全拉黑一遍
                        if ($result === null && $retry > 100) $retry = 100;
                        // 对端临时挂掉是常态，所以只在 debug；连续失败的后果由下面 127 那条 error 兜
                        Club_Log_Event('debug', 'push failed, will retry',
                            ['club' => $task['club'], 'target' => $task['target'], 'retry' => $retry]);
                        if ($retry <= 3) $timestamp = time() + 60;
                        elseif ($retry <= 5) $timestamp = time() + 300;
                        elseif ($retry <= 10) $timestamp = time() + 600;
                        elseif ($retry <= 100) $timestamp = time() + 3600;
                        else $timestamp = time() + 86400;
                        if ($retry == 127) {
                            // 停止对整个实例投递是个大事件，不记的话事后完全无从追溯
                            Club_Log_Event('error', 'target blacklisted after '.$retry.' failed pushes: '.$task['target']);
                            $pdo = $db->prepare('insert ignore into `blacklist`(`target`, `create`) values (:target, :create);');
                            $pdo->execute([':target' => $task['target'], ':create' => time()]);
                            $pdo = $db->prepare('delete from `queues` where `id` = :id');
                            $pdo->execute([':id' => $task['id']]);
                            $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                            $pdo->execute([':tid' => $task['tid']]);
                        } else {
                            $pdo = $db->prepare('update `queues` set `inuse` = 0, `retry` = :retry, `timestamp` = :timestamp where `id` = :id');
                            $pdo->execute([':id' => $task['id'], ':retry' => $retry, ':timestamp' => $timestamp]);
                        }
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
    // 0 关掉这道闸。调高之前先想清楚 DNS 缓存要占多少：dns-cache 一条约 0.4 KB，
    // 装满 8192 条就是 3.2 MB，加上基线还留不下余量的话，这里跟着一起抬
    $limit = ($config['node']['memory-limit'] ?? 10) * 1024 * 1024;
    if ($limit > 0 && ($usage = memory_get_usage(1)) > $limit) {
        global $stop; $stop = true;
        // 多进程模式下 master 会补一个回来，单进程则要靠容器重启
        Club_Log_Console('error', 'memory limit exceeded, stopping',
            ['bytes' => $usage, 'limit' => $limit, 'pid' => getmypid()]);
    }
}
