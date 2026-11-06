<?php

function worker($type) {
    global $config;
    $now = time(); $worked = false;
    try {
        Club_Log_Sync_Error();
        switch ($type) {
            case 'maintain': $worked = worker_maintain($now, $config); break;
            case 'probe': $worked = worker_probe($now); break;
            default: $worked = worker_delivery($type, $now); break;
        }
        worker_idle($worked, $type);
    } finally {
        Club_Log_Ref('');
        Club_Stat_Flush();
    }
    if (($usage = memory_get_usage(1)) > 10 * 1024 * 1024) {
        global $stop; $stop = true;
        Club_Log_Console('error', 'memory limit exceeded, stopping', ['bytes' => $usage, 'pid' => getmypid()]);
    }
}

function worker_idle($worked, $type) {
    static $step = 0, $wait = [50, 100, 200, 500, 1000, 2000, 5000, 15000, 30000];
    if ($worked) { $step = 0; return; }
    $limit = $type == 'probe' ? 30000 : 2000;
    $ms = min($wait[min($step, count($wait) - 1)], $limit); $step++;
    $ms = min($limit, (int)($ms * mt_rand(80, 120) / 100));
    Club_Stat('idle_ms', $ms);
    usleep($ms * 1000);
}

function worker_delivery($category, $now) {
    return ($lease = Club_Endpoint_Claim($category, $now)) ? worker_endpoint($lease, $now) : false;
}

function worker_endpoint($lease, $now) {
    $url = $lease['url']; $token = $lease['token']; $category = $lease['category']; $start = microtime(true);
    if (!($task = Club_Endpoint_Queue($url, $token, $category, $now))) {
        Club_Log_Event('debug', 'endpoint claim has no eligible queue', ['endpoint' => $url]);
        worker_finish('endpoint release', function () use ($url, $token) { Club_Endpoint_Release($url, $token); });
        Club_Stat('endpoint_ms', (int)((microtime(true) - $start) * 1000));
        return true;
    }
    Club_Log_Ref('queue#'.$task['id']);
    $active = $token;
    $result = ActivityPub_Push($task['target'], $task['club'], $task['jsonld'], function () use ($url, $task, $category, &$active) {
            if (!Club_Endpoint_Authorize($url, $active, $next = Club_Lease_Token(), $task['id'], $category)) return false;
            $active = $next;
            return true;
        });
    worker_finish('endpoint completion', function () use ($url, $active, $task, $result) {
        if ($result == 'lease-lost') Club_Endpoint_Release($url, $active);
        else Club_Endpoint_Result($url, $active, $task, $result);
    });
    Club_Stat('endpoint_ms', (int)((microtime(true) - $start) * 1000));
    return true;
}

// Retry only the database write. The remote may have accepted the POST.
function worker_finish($what, $run) {
    try { return Club_DB_Retry($what, $run); }
    catch (PDOException $e) {
        Club_Log_Event('error', 'result could not be recorded, waiting for lease expiry', ['at' => $what, 'error' => $e->getMessage()]);
        return false;
    }
}

function worker_probe($now) {
    if (!($lease = Club_Blacklist_Claim($now))) return false;
    $target = $lease['target']; $token = $lease['token'];
    if (!($club = Club_Group_Signer())) {
        worker_finish('probe defer', function () use ($target, $token) { Club_Blacklist_Defer($target, $token, 'no system club'); });
        return true;
    }
    $start = microtime(true);
    Club_Log_Ref('probe '.$target);
    $active = $token;
    $result = ActivityPub_Probe($target, $club, function () use ($target, &$active) {
        if (!Club_Blacklist_Authorize($target, $active, $next = Club_Lease_Token())) return false;
        $active = $next;
        return true;
    });
    worker_finish('probe completion', function () use ($target, $active, $result) {
        if (in_array($result, ['local-dns', 'local-proxy', 'lease-lost'], true)) Club_Blacklist_Defer($target, $active, $result);
        else Club_Blacklist_Result($target, $active, $result == 'alive');
    });
    Club_Stat('probe_'.$result);
    Club_Stat('probe_ms', (int)((microtime(true) - $start) * 1000));
    return true;
}

function worker_maintain($now, $config) {
    global $db;
    static $leader = null, $due = [], $cursor = 0;
    $pdo = $db->prepare('select get_lock(:lock, 0)');
    $pdo->execute([':lock' => 'wxwclub_maintain:'.md5($config['mysql']['database'])]);
    $lock = $pdo->fetch(PDO::FETCH_COLUMN, 0);
    if ($lock === null) throw new PDOException('maintenance lock returned null');
    $held = (bool)$lock;
    if ($held !== $leader) Club_Log_Event('info', $held ? 'maintenance leader acquired' : 'maintenance standby, another master holds the lock', ['pid' => getmypid()]);
    if (!$held) { $leader = false; return false; }
    if ($leader !== true) { $due = []; Club_Monitor_Count(null); Club_Monitor_Snapshot(true); } $leader = true;
    $every = ['rotate' => 3600, 'reconcile' => 60, 'cleanup' => 30, 'dns' => 600, 'tasks' => 60, 'monitor' => 300];
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
                    case 'rotate': Club_Log_Rotate($config['node']['log-retention'] ?? 30); break;
                    case 'monitor': Club_Monitor_Snapshot(); break;
                    case 'reconcile': Club_Endpoint_Sweep(); break;
                    case 'cleanup': if (Club_Blacklist_Cleanup($batch = 500) >= $batch) $due[$unit] = $now; break;
                    case 'dns': Club_Resolver_Cleanup(); break;
                    case 'tasks': if (worker_expire()) $due[$unit] = $now; break;
                }
            });
        } catch (PDOException $e) {
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

function worker_expire() {
    static $notice_pending = false;
    global $config;
    $notices = Club_Notice_Expire($config['notice']['retention'] ?? 30, 20, $notice_pending ? 0 : 600);
    $notice_pending = $notices;
    $tasks = Club_Task_Cleanup();
    return $notices || $tasks;
}
