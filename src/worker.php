<?php require_once(__DIR__.'/function.php');

function worker() {
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
                    if (ActivityPub_POST($task['target'], $task['club'], $task['jsonld'])) {
                        // 投递成功是 debug：正常运行时每条投稿都会刷一行乘以关注实例数
                        Club_Log_Event('debug', 'push delivered', ['club' => $task['club'],
                            'target' => $task['target'], 'retry' => $task['retry']]);
                        $pdo = $db->prepare('delete from `queues` where `id` = :id');
                        $pdo->execute([':id' => $task['id']]);
                        $pdo = $db->prepare('update `tasks` set `queues` = `queues` - 1 where `tid` = :tid');
                        $pdo->execute([':tid' => $task['tid']]);
                    } else {
                        $retry = $task['retry'] + 1;
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
        Club_Log_Rotate($config['node']['log-retention'] ?? 30);
        // 长期进程要自己换天，rotate 也可能刚把当前这个文件清掉
        Club_Log_Error_Path();
        // 会入队新任务，必须放在下面依赖 last_insert_id() 的语句之前
        Club_Notice_Expire($config['notice']['retention'] ?? 30);
        $pdo = $db->prepare('delete from `tasks` where `queues` < 1 and `timestamp` <= :timestamp');
        $pdo->execute([':timestamp' => time() - 30]);
        $pdo = $db->prepare('update `queues` set `inuse` = 0 where `inuse` = 1 and `timestamp` <= :timestamp');
        $pdo->execute([':timestamp' => time() - 30]);
        $pdo = $db->prepare('update `blacklist` set `inuse` = 0 where `inuse` = 1 and `timestamp` <= :timestamp');
        $pdo->execute([':timestamp' => time() - 30]);
        $pdo = $db->prepare('update `blacklist` set `id` = last_insert_id(id), `inuse` = 1, `timestamp` = :timestamp where `inuse` = 0 and `timestamp` <= :timestamp order by `timestamp` asc limit 1');
        $pdo->execute([':timestamp' => time()]);
        $pdo = $db->query('select `id`, `retry`, `target` from `blacklist` where `id` = last_insert_id() and row_count() <> 0');
        if (($target = $pdo->fetch(PDO::FETCH_ASSOC)) && ($club = Club_System())) {
            if (ActivityPub_POST($target['target'], $club, '{}')) {
                $pdo = $db->prepare('delete from `blacklist` where `id` = :id');
                $pdo->execute([':id' => $target['id']]);
            } else {
                $pdo = $db->prepare('update `blacklist` set `inuse` = 0, `retry` = :retry, `timestamp` = :timestamp where `id` = :id');
                $pdo->execute([':id' => $target['id'], ':retry' => $target['retry'] + 1, ':timestamp' => time() + 86400]);
            }
        } elseif ($idle) sleep(1); $cycle = 0;
    }
    if (memory_get_usage(1) > 10 * 1024 * 1024) {
        global $stop; $stop = true;
        Club_Log_Console('error', 'Memory limit exceeded, stopping ...');
    }
}
