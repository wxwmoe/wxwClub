<?php require_once(__DIR__.'/function.php');

// 三种队列各跑各的。维护要 glob 整个 logs/、还要按游标扫表，是全站一份的活，每个进程各跑一遍就是把同样的事做 N 遍；
// 而投递和探活都会在系统 resolver 和 curl 上一卡十几秒，跟维护混在一起的话，持续的 backlog 会让维护永远轮不上。所以维护队列绝不碰 resolver、curl、endpoint 投递和黑名单探活
function worker($type) {
    global $config;
    $now = time(); $worked = false;
    try {
        // 长期进程要自己换天，rotate 也可能刚把当前这个文件清掉。ini_set 的作用范围是本进程，这条不能跟着类型一起跳过
        Club_Log_Error_Path();
        switch ($type) {
            case 'maintain': $worked = worker_maintain($now, $config); break;
            case 'probe': $worked = worker_probe($now); break;
            default: $worked = worker_delivery($now); break;
        }
        worker_idle($worked, $type);
    } finally {
        // 这条任务到此为止，后面的汇总和下一轮都不算它的。汇总放这里是因为维护单元抛出的那一轮恰恰最该看见窗口计数，跟着异常一起丢掉的话，出事的那一分钟正好没有记录；
        // 异常路径的节流由 worker_loop 负责，这里不再退避一次
        Club_Log_Ref('');
        Club_Stat_Flush();
    }
    if (($usage = memory_get_usage(1)) > 10 * 1024 * 1024) {
        global $stop; $stop = true;
        // 退出后 master 会补一个回来，手上那条投递的租约到期由别人重领
        Club_Log_Console('error', 'memory limit exceeded, stopping',
            ['bytes' => $usage, 'pid' => getmypid()]);
    }
}

// 领不到活时的退避。几十个进程各自每秒查一遍数据库，光空转就是每秒上百条语句；抖动是为了它们别齐步走。投递退到 2 秒封顶，新活动最多等这么久；
// 探活的 check_at 本来就是按天摊开的，慢半分钟没有任何影响，可以退得更狠
function worker_idle($worked, $type) {
    static $step = 0, $wait = [50, 100, 200, 500, 1000, 2000, 5000, 15000, 30000];
    if ($worked) { $step = 0; return; }
    $limit = $type == 'probe' ? 30000 : 2000;
    $ms = min($wait[min($step, count($wait) - 1)], $limit); $step++;
    $ms = min($limit, (int)($ms * mt_rand(80, 120) / 100));
    Club_Stat('idle_ms', $ms);
    usleep($ms * 1000);
}

function worker_delivery($now) {
    return ($lease = Club_Endpoint_Claim($now)) ? worker_endpoint($lease, $now) : false;
}

// 一条 endpoint 的完整投递。所有权在 DNS 之前就拿到了，出网之前还要再换一次 token：解析和 curl 各有超时但合起来没有上界，跨过 120 秒租约之后这条 endpoint 已经易主
function worker_endpoint($lease, $now) {
    $url = $lease['url']; $token = $lease['token']; $start = microtime(true);
    if (!($task = Club_Endpoint_Queue($url, $token, $now))) {
        // 领取后的 token、退避、黑名单或 queue 已经变化，按当前状态重排后放手
        Club_Log_Event('debug', 'endpoint claim has no eligible queue', ['endpoint' => $url]);
        worker_finish('endpoint release', function () use ($url, $token) {
            Club_Endpoint_Release($url, $token);
        });
        Club_Stat('endpoint_ms', (int)((microtime(true) - $start) * 1000));
        return true;
    }
    // worker 是长期进程，关联标记不像 web 那样每请求自动归零，每条任务都要重设。用队列行号，同一条投递的入队、重试、成功几行就能串起来
    Club_Log_Ref('queue#'.$task['id']);
    if ($task['type'] != 'push') {
        // 认不出来的类型永远处理不掉，留着只会被反复领取。丢掉这一条，但不能当成终局拒绝：那条路会连带清掉 endpoint 的故障段，而这里根本没跟对端说过话
        Club_Log_Event('warning', 'queue dropped, unknown task type', ['id' => $task['id'], 'type' => $task['type'], 'target' => $url]);
        worker_finish('endpoint completion', function () use ($url, $token, $task) {
            Club_Endpoint_Complete($url, $token, $task, 'dropped');
        });
        Club_Stat('endpoint_ms', (int)((microtime(true) - $start) * 1000));
        return true;
    }
    $next = Club_Token(); $active = $token;
    $result = ActivityPub_POST($task['target'], $task['club'], $task['jsonld'],
        function () use ($url, $token, $next, $task, &$active) {
            if (!Club_Endpoint_Authorize($url, $token, $next, $task['id'])) return false;
            $active = $next;
            return true;
        });
    worker_finish('endpoint completion', function () use ($url, $token, $active, $task, $result) {
        // 没拿到发送权：这次一个字节都没出网，旧 token 还能不能放掉由数据库说了算
        if ($result == 'lease-lost') Club_Endpoint_Release($url, $token);
        // authorize 之前返回时仍认领取 token，出网闸门通过后只认新 token
        else Club_Endpoint_Complete($url, $active, $task, $result);
    });
    Club_Stat('endpoint_ms', (int)((microtime(true) - $start) * 1000));
    return true;
}

// HTTP 之后那段短事务的收尾。死锁重来一次就好，但重来的只有数据库这一段 —— 已经发出去的请求收不回来，绝不能跟着再发一次。
// 重试到头也不能硬来：保留 queue 和租约，等它自然过期，由 at-least-once 重投兜底
function worker_finish($what, $run) {
    try { return Club_DB_Retry($what, $run); }
    catch (PDOException $e) {
        Club_Log_Event('error', 'result could not be recorded, waiting for lease expiry', ['at' => $what, 'error' => $e->getMessage()]);
        return false;
    }
}

// 黑名单探活。跟投递同一套 token 协议：解析之后、出网之前换 token 并续租，失去所有权的旧 probe 不能再发请求
function worker_probe($now) {
    if (!($lease = Club_Blacklist_Claim($now))) return false;
    $target = $lease['target']; $token = $lease['token'];
    if (!($club = Club_System())) {
        // 没有系统群组就签不了名，这次探活发不出去，别把 checks 记在对端头上
        worker_finish('probe defer', function () use ($target, $token) {
            Club_Blacklist_Defer($target, $token, 'no system club');
        });
        return true;
    }
    $start = microtime(true);
    Club_Log_Ref('probe '.$target);
    $next = Club_Token(); $active = $token;
    $result = ActivityPub_Probe($target, $club, function () use ($target, $token, $next, &$active) {
        if (!Club_Blacklist_Authorize($target, $token, $next)) return false;
        $active = $next;
        return true;
    });
    worker_finish('probe completion', function () use ($target, $token, $active, $result) {
        // 本站 DNS 的事，对端一个字都没说过：checks 不动，只短推 check_at，也不能把租约留到自然过期 —— 那 120 秒里这一行谁都探不了
        if ($result == 'local-dns' || $result == 'lease-lost')
            Club_Blacklist_Defer($target, $token, $result);
        else Club_Blacklist_Result($target, $active, $result == 'alive');
    });
    // 只有总时长的话，看不出这批探活是真在问对端还是一直卡在本站 DNS 上，而这两种「慢」要加的是完全不同的东西
    Club_Stat('probe_'.$result);
    Club_Stat('probe_ms', (int)((microtime(true) - $start) * 1000));
    return true;
}

// 维护队列的一轮：按 deadline 轮转，每次最多做一个有界工作单元，做完立刻回来重新判
function worker_maintain($now, $config) {
    static $due = [], $cursor = 0;
    // rotate 自己带 1 小时节流，这里的间隔只决定多久去问它一次
    $every = ['rotate' => 300, 'reconcile' => 60, 'cleanup' => 30,
        'dns' => 600, 'tasks' => 60, 'monitor' => 60];
    foreach ($every as $unit => $interval) if (!isset($due[$unit])) $due[$unit] = 0;
    $units = array_keys($every); $count = count($units);
    for ($offset = 0; $offset < $count; $offset++) {
        $index = ($cursor + $offset) % $count; $unit = $units[$index]; $interval = $every[$unit];
        if ($due[$unit] > $now) continue;
        $cursor = ($index + 1) % $count;
        $due[$unit] = $now + $interval; $start = microtime(true);
        try {
            Club_DB_Retry('maintenance '.$unit, function () use ($unit, $config, $now, &$due) {
                switch ($unit) {
                    case 'rotate':
                        Club_Log_Rotate($config['node']['log-retention'] ?? 30); break;
                    case 'monitor':
                        // 自己按 5 分钟节流，这里只保证有人足够频繁地去问它一次
                        Club_Monitor_Snapshot(); break;
                    case 'reconcile':
                        Club_Reconcile_Step(); break;
                    case 'cleanup':
                        // 清完一整批就说明还有 backlog，下一轮立刻接着清
                        if (Club_Blacklist_Cleanup($batch = 500) >= $batch) $due[$unit] = $now; break;
                    case 'dns':
                        Club_Resolver_Cleanup(); break;
                    case 'tasks':
                        if (worker_expire()) $due[$unit] = $now; break;
                }
            });
        } catch (PDOException $e) {
            // Club_DB_Retry 已经重试过了，还失败就不是瞬时争用。立刻重排的话，配上 worker_loop 的 1 秒退避就是同一行 ERROR 每秒刷一条。
            // 取当前时间而不是进本轮时的 $now：锁等待本身可能已经烧掉几十秒，那样 $now + 5 早就是过去时，退避等于没加
            $due[$unit] = time() + 5;
            Club_Monitor_Count($unit.'_errors');
            Club_Log_Event('error', 'maintenance unit failed', ['unit' => $unit, 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            $elapsed = (int)((microtime(true) - $start) * 1000);
            Club_Monitor_Count($unit.'_runs');
            Club_Monitor_Count($unit.'_ms', $elapsed);
            Club_Stat('maintenance_runs');
            Club_Stat('maintenance_ms', $elapsed);
        }
        return true;
    }
    return false;
}

// 过期清理。撤回提醒会新入队活动，所以它必须排在只认 queues 的 task 清理前面
function worker_expire() {
    static $notice_pending = false;
    global $config;
    $notices = Club_Notice_Expire($config['notice']['retention'] ?? 30, 20, $notice_pending ? 0 : 600);
    $notice_pending = $notices;
    // tasks 只认真实 queues 行，详细判据和批次上限收在同一个清理入口
    $tasks = Club_Task_Cleanup();
    return $notices || $tasks;
}
