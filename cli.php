#!/usr/bin/env php
<?php if (PHP_SAPI != 'cli') exit("The program runs only in CLI mode!\n");

if (!isset($argv[1])) { cli_usage(); exit(1); }
if ($argv[1] === 'help') { cli_usage(); exit(0); }

if ($argv[1] === 'test') {
    array_splice($argv, 1, 1);
    require(__DIR__.'/tests/suite.php');
    exit;
}

require(__DIR__.'/app/bootstrap.php');
require_once(APP_ROOT.'/app/worker.php');

$stop = false;
declare(ticks = 1);

function shutdown() {
    global $stop; $stop = true;
    // master 和几个子进程都会各记一行，看的就是谁还没收到
    Club_Log_Console('info', 'shutdown requested, finishing current task', ['pid' => getmypid()]);
};

// 子进程的去向：正常退出记 code，被信号带走记 signal，9 多半是宽限期不够被强杀的。补进程和关停收尾两处都要记，抄两遍迟早漏一处
function worker_reaped($slot, $pid, $status) {
    global $slots;
    if (pcntl_wifsignaled($status)) {
        $level = 'warning'; $result = 'signal '.pcntl_wtermsig($status);
    } elseif (pcntl_wifexited($status)) {
        $code = pcntl_wexitstatus($status);
        $level = $code ? 'error' : 'info'; $result = 'code '.$code;
    } else {
        $level = 'warning'; $result = 'unknown';
    }
    Club_Log_Console($level, 'worker exited', ['slot' => $slot, 'type' => $slots[$slot] ?? '?', 'pid' => $pid, 'status' => $result]);
}

// 一个 worker 的主循环。多进程模式下这就是子进程的全部工作，declare(ticks) 是文件作用域的，循环留在这个文件里信号才收得到
function worker_loop($type) {
    global $stop, $db;
    // 在第一条任务开始前打开统计窗口；否则首条慢请求刚结束就停机时，force flush 只能看到一秒窗口，busy_ratio 和吞吐率都会被夸大。
    Club_Stat_Flush();
    while (!$stop) {
        try { worker($type); }
        // 只 echo 到 stdout 的话，进程被托管起来跑时这些报错等于没有
        catch (PDOException $e) {
            // 多进程抢同一张队列表，偶尔会撞出死锁，重来一次就好；连成片才是真出事了
            Club_Log_Console('error', 'database error', ['error' => $e->getMessage(), 'pid' => getmypid()]);
            Club_Stat('db_retries');
            // 连接断了就重连。长期进程握着一个失效的 PDO 只会每秒重复报同一行错，而队列那边看起来只是没人在投递
            try { if ($db) $db->query('select 1'); else Club_DB_Connect(); }
            catch (PDOException $lost) {
                try {
                    Club_DB_Connect();
                    Club_Log_Console('info', 'database reconnected', ['pid' => getmypid()]);
                } catch (PDOException $down) {
                    Club_Log_Console('error', 'database reconnect failed', ['error' => $down->getMessage(), 'pid' => getmypid()]);
                }
            }
            sleep(1);
        }
    }
    // 退出前把这一窗口攒下的计数写出去，不然最后一段时间等于没有记录
    Club_Stat_Flush(true);
}

function cli_error($message, $code = 1) {
    fwrite(STDERR, 'Error: '.$message."\n");
    return $code;
}

function cli_usage() {
    echo <<<'TEXT'
Usage:
  php cli.php club list [--after CID|--before CID] [--order asc|desc] [--limit 50] [--json]
  php cli.php club show <club> [--json]
  php cli.php club set <club> <nickname|infoname|summary|avatar|banner> <value>
  php cli.php club clear <club> <nickname|infoname|summary|avatar|banner>
  php cli.php club publish <club>
  php cli.php user fetch <handle|actor-url>
  php cli.php user groups <handle|actor-url> [--json]
  php cli.php follow add|remove <handle|actor-url> <club>
  php cli.php dns show|refresh <host> [--json]
  php cli.php queue show <host|target> [--json]
  php cli.php queue purge <host|target> [--yes]
  php cli.php endpoint show <host|target> [--json]
  php cli.php blacklist show|probe|add <host|target> [--json]
  php cli.php worker|migrate|test [group...]
  php cli.php status [--json]
TEXT;
    echo "\n";
}

function cli_flag(&$args, $name) {
    $key = array_search($name, $args, true);
    if ($key === false) return false;
    array_splice($args, $key, 1);
    return true;
}

function cli_option(&$args, $name, $default = null) {
    $key = array_search($name, $args, true);
    if ($key === false) return $default;
    if (!isset($args[$key + 1])) throw new InvalidArgumentException($name.' requires a value');
    $value = $args[$key + 1]; array_splice($args, $key, 2);
    return $value;
}

function cli_emit($data, $lines = []) {
    global $cli_json;
    if ($cli_json) echo Club_Json_Encode($data), "\n";
    else foreach ((array)$lines as $line) echo $line, "\n";
}

function cli_time($timestamp, $never = false) {
    if (!isset($timestamp)) return '-';
    if ($never && (int)$timestamp === 4111110000) return '永不';
    return date('Y-m-d H:i:s', (int)$timestamp);
}

// LIKE 只负责缩小候选，最后必须按 URL 解析出的 host 精确比较；否则 example.com 会误中 notexample.com，甚至 path/query 里的同名字符串。
function cli_targets($input, $source) {
    global $db;
    if (($target = Club_Endpoint_Normalize($input)) !== false) return [$target];
    if (($host = Club_Url_Host($input)) === false) throw new InvalidArgumentException('expected a host or normalized HTTP(S) target');
    $sources = [
        'blacklist' => ['target', 'blacklist'],
        'endpoints' => ['url', 'endpoints'],
        'queues' => ['target', 'queues'],
        'users' => ['shared_inbox', 'users']
    ];
    if (!isset($sources[$source])) throw new InvalidArgumentException('unknown target source');
    list($column, $table) = $sources[$source];
    $pdo = $db->prepare('select distinct `'.$column.'` from `'.$table.'` where `'.$column.'` like :like order by `'.$column.'` collate ascii_bin');
    $pdo->execute([':like' => '%'.$host.'%']); $targets = [];
    foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $url) {
        $found = parse_url($url, PHP_URL_HOST);
        if (is_string($found) && strcasecmp(rtrim(trim($found, '[]'), '.'), $host) === 0) $targets[] = $url;
    }
    return $targets;
}

function cli_user($input) {
    global $db;
    $input = trim((string)$input);
    if (preg_match('#^https?://#i', $input)) {
        $pdo = $db->prepare('select * from `users` where `actor` = :actor'); $pdo->execute([':actor' => $input]);
        return $pdo->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $name = ltrim($input, '@');
    $pdo = $db->prepare('select * from `users` where `name` = :name order by `uid` limit 2'); $pdo->execute([':name' => $name]);
    $rows = $pdo->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) throw new RuntimeException('more than one cached actor uses this handle; pass the actor URL');
    return $rows ? $rows[0] : null;
}

function cli_table($rows, $columns) {
    if (!$rows) return ['No rows.'];
    $lines = [implode("\t", array_values($columns))];
    foreach ($rows as $row) {
        $values = [];
        foreach (array_keys($columns) as $key) $values[] = isset($row[$key]) ? (string)$row[$key] : '-';
        $lines[] = implode("\t", $values);
    }
    return $lines;
}

function cli_manage($resource, $action, $args) {
    global $db, $base, $cli_json;
    $cli_json = cli_flag($args, '--json');
    if (Club_DB_Version() !== DB_VERSION) return cli_error('database schema does not match this code; run php cli.php migrate');

    if ($resource === 'club' && $action === 'list') {
        $limit = (int)cli_option($args, '--limit', 50); $order = strtolower(cli_option($args, '--order', 'asc'));
        $after = cli_option($args, '--after'); $before = cli_option($args, '--before');
        if ($args || $limit < 1 || $limit > 200 || !in_array($order, ['asc', 'desc'], true) || (isset($after) && isset($before)) ||
            (isset($after) && (!ctype_digit((string)$after) || (int)$after < 1)) || (isset($before) && (!ctype_digit((string)$before) || (int)$before < 1)))
            return cli_error('usage: php cli.php club list [--after CID|--before CID] [--order asc|desc] [--limit 50]');
        if ($order === 'asc' && isset($before) || $order === 'desc' && isset($after)) return cli_error('--after belongs to asc order and --before belongs to desc order');
        $cursor = isset($after) ? (int)$after : (isset($before) ? (int)$before : null); $where = '';
        if (isset($cursor)) $where = ' where `c`.`cid` '.($order === 'asc' ? '>' : '<').' :cursor';
        $sql = 'select `c`.`cid`, `c`.`name`, `c`.`nickname`, `c`.`timestamp`, (select count(*) from `followers` `f` where `f`.`cid` = `c`.`cid`) as `followers`,' .
            ' (select count(*) from `announces` `a` where `a`.`cid` = `c`.`cid`) as `announces` from `clubs` `c`'.$where.' order by `c`.`cid` '.$order.' limit '.($limit + 1);
        $pdo = $db->prepare($sql); $pdo->execute(isset($cursor) ? [':cursor' => $cursor] : []); $rows = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > $limit; if ($more) array_pop($rows);
        foreach ($rows as &$row) $row['created'] = cli_time($row['timestamp']); unset($row);
        $next = $more && $rows ? (int)$rows[count($rows) - 1]['cid'] : null;
        $lines = cli_table($rows, ['cid' => 'CID', 'name' => 'NAME', 'nickname' => 'NICKNAME', 'followers' => 'FOLLOWERS', 'announces' => 'ANNOUNCES', 'created' => 'CREATED']);
        if (!$cli_json && isset($next)) $lines[] = 'Next: php cli.php club list --'.($order === 'asc' ? 'after' : 'before').' '.$next.' --order '.$order.' --limit '.$limit;
        cli_emit(['items' => $rows, 'has_more' => $more, $order === 'asc' ? 'next_after' : 'next_before' => $next], $lines);
        return 0;
    }

    if ($resource === 'club' && $action === 'show') {
        if (count($args) !== 1) return cli_error('usage: php cli.php club show <club>');
        if (!($actor = Club_Group_Actor($args[0], $row))) return cli_error('club not found', 2);
        $pdo = $db->prepare('select (select count(*) from `followers` where `cid` = :cid) as `followers`, (select count(*) from `announces` where `cid` = :cid2) as `announces`,' .
            ' (select count(*) from `tasks` `t` where `t`.`cid` = :cid3 and exists (select 1 from `queues` `q` where `q`.`tid` = `t`.`tid`)) as `tasks`');
        $pdo->execute([':cid' => $row['cid'], ':cid2' => $row['cid'], ':cid3' => $row['cid']]); $counts = $pdo->fetch(PDO::FETCH_ASSOC);
        $data = ['cid' => (int)$row['cid'], 'name' => $row['name'], 'actor' => $actor['id'], 'effective' => ['nickname' => $actor['name'], 'summary' => $actor['summary'],
            'avatar' => $actor['icon']['url'], 'banner' => $actor['image']['url']], 'overrides' => ['nickname' => $row['nickname'], 'infoname' => $row['infoname'], 'summary' => $row['summary'],
            'avatar' => $row['avatar'], 'banner' => $row['banner']], 'followers' => (int)$counts['followers'], 'announces' => (int)$counts['announces'], 'tasks' => (int)$counts['tasks'],
            'created_at' => (int)$row['timestamp'], 'public_key_sha256' => hash('sha256', $row['public_key'])];
        cli_emit($data, ['cid:                '.$data['cid'], 'name:               '.$data['name'], 'actor:              '.$data['actor'], 'nickname:           '.$data['effective']['nickname'],
            'summary:            '.$data['effective']['summary'], 'avatar:             '.$data['effective']['avatar'], 'banner:             '.$data['effective']['banner'],
            'followers:          '.$data['followers'], 'announces:          '.$data['announces'], 'pending tasks:      '.$data['tasks'], 'created:            '.cli_time($data['created_at']),
            'public key sha256:  '.$data['public_key_sha256']]);
        return 0;
    }

    if ($resource === 'club' && ($action === 'set' || $action === 'clear')) {
        $need = $action === 'set' ? 3 : 2;
        if (count($args) !== $need) return cli_error('usage: php cli.php club '.$action.' <club> <field>'.($action === 'set' ? ' <value>' : ''));
        if (!Club_Group_Set($args[0], $args[1], $action === 'set' ? $args[2] : null)) return cli_error('club not found', 2);
        cli_emit(['club' => $args[0], 'field' => $args[1], 'cleared' => $action === 'clear'], ['Updated '.$args[0].'.'.$args[1].'.']);
        return 0;
    }

    if ($resource === 'club' && $action === 'publish') {
        if (count($args) !== 1) return cli_error('usage: php cli.php club publish <club>');
        if (($result = Club_Group_Publish($args[0])) === false) return cli_error('club not found', 2);
        if (!$result['targets']) {
            cli_emit($result, ['No follower shared inboxes to notify.']); return 0;
        }
        if (!$result['queued']) return cli_error('could not queue the profile update');
        cli_emit($result, ['Queued profile Update for '.$result['targets'].' shared inbox(es) representing '.$result['followers'].' follower(s).']);
        return 0;
    }

    if ($resource === 'user' && $action === 'fetch') {
        if (count($args) !== 1) return cli_error('usage: php cli.php user fetch <handle|actor-url>');
        if (!($actor = Club_Actor_Resolve($args[0]))) return cli_error('could not resolve actor');
        if (!($user = Club_Actor_Fetch($actor))) return cli_error('could not fetch actor');
        $pdo = $db->prepare('select * from `users` where `uid` = :uid'); $pdo->execute([':uid' => $user['uid']]); $user = $pdo->fetch(PDO::FETCH_ASSOC);
        $data = ['uid' => (int)$user['uid'], 'name' => $user['name'], 'actor' => $user['actor'], 'inbox' => $user['inbox'], 'shared_inbox' => $user['shared_inbox'],
            'timestamp' => (int)$user['timestamp'], 'refresh' => (int)$user['refresh'], 'public_key_sha256' => hash('sha256', $user['public_key'])];
        $data['timestamp_text'] = cli_time($user['timestamp']); $data['refresh_text'] = cli_time($user['refresh']);
        cli_emit($data, ['uid:          '.$user['uid'], 'name:         '.$user['name'], 'actor:        '.$user['actor'], 'inbox:        '.$user['inbox'],
            'shared_inbox: '.$user['shared_inbox'], 'created:      '.$data['timestamp_text'], 'refreshed:    '.$data['refresh_text']]);
        return 0;
    }

    if ($resource === 'user' && $action === 'groups') {
        if (count($args) !== 1) return cli_error('usage: php cli.php user groups <handle|actor-url>');
        if (!($user = cli_user($args[0]))) return cli_error('user is not cached; run php cli.php user fetch first', 2);
        $pdo = $db->prepare('select `c`.`cid`, `c`.`name`, `c`.`nickname`, `f`.`timestamp` from `followers` `f` join `clubs` `c` on `f`.`cid` = `c`.`cid` where `f`.`uid` = :uid order by `c`.`cid`');
        $pdo->execute([':uid' => $user['uid']]); $rows = $pdo->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['followed'] = cli_time($row['timestamp']); unset($row);
        cli_emit(['user' => $user['actor'], 'groups' => $rows], cli_table($rows, ['cid' => 'CID', 'name' => 'NAME', 'nickname' => 'NICKNAME', 'followed' => 'FOLLOWED']));
        return 0;
    }

    if ($resource === 'follow' && ($action === 'add' || $action === 'remove')) {
        if (count($args) !== 2) return cli_error('usage: php cli.php follow add|remove <handle|actor-url> <club>');
        if (!($user = cli_user($args[0]))) return cli_error('user is not cached; run php cli.php user fetch first', 2);
        $state = Club_Group_Follow($user['actor'], $args[1], $action === 'add');
        if ($state === 'club-missing') return cli_error('club not found', 2);
        cli_emit(['state' => $state, 'actor' => $user['actor'], 'club' => $args[1]], [$state.': '.$user['actor'].' -> '.$args[1]]);
        return 0;
    }

    if ($resource === 'dns' && ($action === 'show' || $action === 'refresh')) {
        if (count($args) !== 1 || ($host = Club_Url_Host($args[0])) === false) return cli_error('usage: php cli.php dns '.$action.' <host>');
        if ($action === 'refresh') {
            $ips = Club_Resolver_Get($host, 0, 0); $row = Club_Resolver_Read($host);
            $deferred = Club_Resolver_Deferred() || ($row && (int)$row['lock_until'] > time());
            $message = $deferred ? 'Deferred: another process owns the refresh.' : 'Refreshed '.$host.': '.($ips ? implode(', ', $ips) : '(no records)');
            cli_emit(['host' => $host, 'ips' => $ips, 'deferred' => $deferred, 'cache' => $row], [$message]);
            return $deferred ? 2 : 0;
        }
        $row = Club_Resolver_Read($host);
        if (!$row) return cli_error('host is not cached', 2);
        $data = ['host' => $host, 'ips' => $row['ips'] === '' ? [] : explode(',', $row['ips']), 'checked_at' => (int)$row['checked_at'], 'lock_until' => (int)$row['lock_until']];
        cli_emit($data, ['host:       '.$host, 'ips:        '.($row['ips'] === '' ? '(negative cache)' : $row['ips']), 'checked:    '.cli_time($row['checked_at']),
            'lock_until: '.((int)$row['lock_until'] ? cli_time($row['lock_until']) : '-')]);
        return 0;
    }

    if ($resource === 'queue' && ($action === 'show' || $action === 'purge')) {
        if (!$args) return cli_error('usage: php cli.php queue '.$action.' <host|target>'.($action === 'purge' ? ' [--yes]' : ''));
        $yes = cli_flag($args, '--yes');
        if (count($args) !== 1) return cli_error('usage: php cli.php queue '.$action.' <host|target>'.($action === 'purge' ? ' [--yes]' : ''));
        $targets = cli_targets($args[0], 'queues');
        if (!$targets) return cli_error('no queue targets matched', 2);
        $rows = []; $total = 0;
        $pdo = $db->prepare('select count(*) as `queues`, count(distinct `tid`) as `tasks`, min(`due_at`) as `first_due`, max(`due_at`) as `last_due` from `queues` where `target` = :target');
        foreach ($targets as $target) {
            $pdo->execute([':target' => $target]); $row = $pdo->fetch(PDO::FETCH_ASSOC); $row['target'] = $target;
            $row['first'] = cli_time($row['first_due']); $row['last'] = cli_time($row['last_due']); $rows[] = $row;
            $total += (int)$row['queues'];
        }
        if ($action === 'show') {
            cli_emit(['items' => $rows, 'queues' => $total], cli_table($rows, ['target' => 'TARGET', 'queues' => 'QUEUES', 'tasks' => 'TASKS', 'first' => 'FIRST DUE', 'last' => 'LAST DUE']));
            return 0;
        }
        if (!$yes) {
            cli_emit(['preview' => true, 'targets' => count($targets), 'queues' => $total], ['Matched '.count($targets).' target(s), '.$total.' queue row(s).', 'Run again with --yes to purge.']);
            return 2;
        }
        $deleted = Club_Queue_Purge($targets);
        cli_emit(['targets' => count($targets), 'deleted' => $deleted], ['Purged '.$deleted.' queue row(s) from '.count($targets).' target(s).']);
        return 0;
    }

    if ($resource === 'endpoint' && $action === 'show') {
        if (count($args) !== 1) return cli_error('usage: php cli.php endpoint show <host|target>');
        $targets = cli_targets($args[0], 'endpoints'); $rows = [];
        $pdo = $db->prepare('select `e`.`url`, `e`.`fails`, `e`.`fail_since`, `e`.`retry_at`, `e`.`next_at`, `e`.`follow_at`, `e`.`notice_at`, `e`.`announce_at`, `e`.`relay_at`, `e`.`idle_since`,'.
            ' `e`.`lease_until`, hex(`e`.`lease_token`) as `lease_token`, (select count(*) from `queues` `q` where `q`.`target` = `e`.`url`) as `queues`,' .
            ' exists(select 1 from `blacklist` `b` where `b`.`target` = `e`.`url`) as `blacklisted` from `endpoints` `e` where `e`.`url` = :target');
        foreach ($targets as $target) { $pdo->execute([':target' => $target]); if ($row = $pdo->fetch(PDO::FETCH_ASSOC)) $rows[] = $row; }
        cli_emit(['items' => $rows], cli_table($rows, ['url' => 'URL', 'fails' => 'FAILS', 'retry_at' => 'RETRY', 'next_at' => 'NEXT', 'queues' => 'QUEUES', 'blacklisted' => 'BLOCKED']));
        return $rows ? 0 : 2;
    }

    if ($resource === 'blacklist' && $action === 'show') {
        if (count($args) !== 1) return cli_error('usage: php cli.php blacklist show <host|target>');
        $targets = cli_targets($args[0], 'blacklist'); $rows = [];
        $pdo = $db->prepare('select `target`,`created_at`,`check_at`,`checks`,`restore_pending_at`,`lease_until` from `blacklist` where `target` = :target');
        foreach ($targets as $target) {
            $pdo->execute([':target' => $target]);
            if ($row = $pdo->fetch(PDO::FETCH_ASSOC)) {
                $row['created'] = cli_time($row['created_at']); $row['next_probe'] = cli_time($row['check_at'], true);
                $row['state'] = isset($row['restore_pending_at']) ? 'cleanup-pending' : ((int)$row['lease_until'] > time() ? 'probing' : 'blocked'); $rows[] = $row;
            }
        }
        cli_emit(['items' => $rows], cli_table($rows, ['target' => 'TARGET', 'checks' => 'CHECKS', 'created' => 'CREATED', 'next_probe' => 'NEXT PROBE', 'state' => 'STATE']));
        return $rows ? 0 : 2;
    }

    if ($resource === 'blacklist' && $action === 'probe') {
        if (count($args) !== 1) return cli_error('usage: php cli.php blacklist probe <host|target>');
        $targets = cli_targets($args[0], 'blacklist');
        if (!$targets) return cli_error('no blacklisted targets matched', 2);
        $updated = Club_Blacklist_Probe_Now($targets);
        cli_emit(['matched' => count($targets), 'updated' => $updated], ['Matched '.count($targets).' target(s); scheduled '.$updated.' probe(s).']);
        return 0;
    }

    if ($resource === 'blacklist' && $action === 'add') {
        if (count($args) !== 1) return cli_error('usage: php cli.php blacklist add <host|target>');
        $direct = Club_Endpoint_Normalize($args[0]); $targets = $direct === false ? cli_targets($args[0], 'users') : [$direct];
        if (!$targets) return cli_error('no shared inbox targets matched this host', 2);
        foreach ($targets as $target) Club_Blacklist_Force($target);
        cli_emit(['targets' => $targets, 'check_at' => 4111110000], array_merge(['Blacklisted '.count($targets).' target(s) permanently:'], $targets));
        return 0;
    }

    if ($resource === 'status' && $action === '') {
        if ($args) return cli_error('usage: php cli.php status');
        $row = $db->query('select (select count(*) from `clubs`) as `clubs`, (select count(*) from `users`) as `users`, (select count(*) from `followers`) as `followers`,' .
            ' (select count(*) from `tasks`) as `tasks`, (select count(*) from `queues`) as `queues`, (select count(*) from `endpoints`) as `endpoints`,' .
            ' (select count(*) from `blacklist`) as `blacklist`, (select count(*) from `dns`) as `dns`')->fetch(PDO::FETCH_ASSOC);
        $row['schema'] = DB_VERSION;
        cli_emit($row, ['schema:    '.$row['schema'], 'clubs:    '.$row['clubs'], 'users:     '.$row['users'], 'followers: '.$row['followers'], 'tasks:     '.$row['tasks'],
            'queues:    '.$row['queues'], 'endpoints: '.$row['endpoints'], 'blacklist: '.$row['blacklist'], 'dns:       '.$row['dns']]);
        return 0;
    }

    return cli_error('unknown command; run php cli.php help');
}

switch ($argv[1]) {
    case 'club':
    case 'user':
    case 'follow':
    case 'dns':
    case 'queue':
    case 'endpoint':
    case 'blacklist':
        try { exit(cli_manage($argv[1], $argv[2] ?? '', array_slice($argv, 3))); }
        catch (InvalidArgumentException $e) { exit(cli_error($e->getMessage())); }
        catch (RuntimeException $e) { exit(cli_error($e->getMessage())); }
    case 'worker':
        // 库里的结构与这份代码不一致时，先进入结构合并闸门。合并期间 web 那边整个入口是 503，这里也不能起队列进程 —— 半新半旧的结构下投递会写出对不上的行。
        // 合并完直接退出，让容器按 restart 策略把正常的队列进程带起来
        try { $version = Club_DB_Version(); }
        catch (Throwable $e) {
            Club_Log_Console('error', 'database schema check failed', ['error' => $e->getMessage(), 'pid' => getmypid()]);
            exit(1);
        }
        if ($version !== DB_VERSION) {
            require_once(APP_ROOT.'/app/database/migrate.php');
            // 合并中途崩了没有事务能回滚，但每一步都会先问一次 information_schema，重启后接着跑不会把已经合并好的部分改坏。这里只负责把原因记下来
            try { $version = Club_Migrate_Run(); }
            catch (Throwable $e) {
                Club_Log_Console('error', 'database merge failed', ['error' => $e->getMessage(), 'pid' => getmypid()]);
                exit(1);
            }
            if ($version !== DB_VERSION) {
                Club_Log_Console('error', 'database schema mismatch, not starting workers', ['schema' => $version, 'expected' => DB_VERSION]);
                exit(1);
            }
            Club_Log_Console('info', 'database merged, exiting for restart', ['schema' => $version]);
            break;
        }
        // 维护是全站一份的活，多开只是把同样的事做 N 遍，所以固定一个、不作配置；起了第二个 master 时它们之间靠命名锁选出一个真正干活，见 worker_maintain。
        // 投递和探活都靠 token 租约互斥，加进程就是加并发，队列逻辑一行都不用改
        $slots = ['maintain.0' => 'maintain'];
        foreach (Club_Config_Delivery_Workers() as $type => $n) for ($i = 0; $i < $n; $i++) $slots[$type.'.'.$i] = $type;
        for ($i = 0, $n = (int)($config['worker']['probe'] ?? 1); $i < $n; $i++) $slots['probe.'.$i] = 'probe';
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_fork') || !function_exists('pcntl_wait')) {
            Club_Log_Console('error', 'worker unavailable, ext-pcntl missing');
            exit(1);
        }
        // 停止时要靠它把 SIGTERM 转发给子进程（docker stop 只发给 master）。缺了的话直到关停那一刻才发作，子进程会变成谁也收不到通知的孤儿，先拦下来
        if (!function_exists('posix_kill')) {
            Club_Log_Console('error', 'worker unavailable, ext-posix missing');
            exit(1);
        }
        pcntl_signal(SIGINT, 'shutdown');
        pcntl_signal(SIGTERM, 'shutdown');
        // master 只管 fork 和收尸，先把 bootstrap 建的连接关掉：带着连接 fork 的话父子共享同一个 socket，谁先断开都会把别人的一起带走
        $db = null;
        // 挪在 master started 之前，那一行是这次运行的起点标记，但不保证是文件第一行：debug 下 shift 自己的早退记录、切分失败的 warning 都排在它前面，级别低于 info 时它压根不落盘
        Club_Log_Shift();
        Club_Log_Console('info', 'master started', ['slots' => array_count_values($slots), 'pid' => getmypid()]);
        // 进了黑名单的对端只有探活能把它捞回来，一个都不开就是永久拉黑。
        // 放在切分之后：它说的是刚起来的这个 master，写在前面就会被稳定地归档进上一段，而 warning 级别下 master started 不写，当天的 .log 那时还没生成，tail 也就看不到这条
        if (!in_array('probe', $slots, true)) Club_Log_Console('warning', 'no probe process configured, blacklisted endpoints will never be restored');
        $children = [];     // pid => 槽位，槽位空出来就补一个回去
        while (!$stop) {
            $taken = array_flip($children);
            foreach ($slots as $slot => $type) {
                if ($stop) break;
                if (isset($taken[$slot])) continue;
                if (($pid = pcntl_fork()) < 0) {
                    Club_Log_Console('error', 'fork failed, will retry', ['slot' => $slot]); sleep(1); break;
                }
                if ($pid === 0) {
                    // 子进程：继承来的名单是父进程的，留着会拿它去 kill 别人的兄弟
                    $children = []; Club_DB_Connect();
                    // 类型加序号：pid 每次重启都变，按这个名字才对得上是哪个位置
                    Club_Worker_Slot($slot);
                    worker_loop($type); exit(0);
                }
                $children[$pid] = $slot; $taken[$slot] = $pid;
                Club_Log_Console('info', 'worker started', ['slot' => $slot, 'type' => $type, 'pid' => $pid]);
            }
            // 不用阻塞版的 wait：SIGTERM 在它进去之后才到的话，得等到有子进程退出才醒得过来，而 docker stop 只给 10 秒。sleep 会被信号打断
            if (($pid = pcntl_wait($status, WNOHANG)) > 0) {
                // 内存超限自杀、或者崩溃，都在这里补回去，不用整个容器重启
                worker_reaped($children[$pid] ?? '?', $pid, $status);
                unset($children[$pid]);
            } elseif (!$stop) sleep(1);
        }
        if ($children) {
            Club_Log_Console('info', 'stopping workers', ['jobs' => count($children)]);
            foreach (array_keys($children) as $pid) posix_kill($pid, SIGTERM);
            // 子进程要跑完手上那条投递才退。宽限期不够被 SIGKILL 也不丢任务，它握着的租约 120 秒后自然过期，别人重新领走就是；那之后旧进程就算复活也拿不到出网权，token 已经不是它的了
            while ($children && ($pid = pcntl_wait($status)) > 0) {
                worker_reaped($children[$pid] ?? '?', $pid, $status);
                unset($children[$pid]);
            }
        }
        Club_Log_Console('info', 'master stopped', ['pid' => getmypid()]); break;
    // 手动合并。worker 启动时会自己判断并合并，这条只是给不方便重启容器的场合留的口子
    case 'migrate':
        require_once(APP_ROOT.'/app/database/migrate.php');
        try { $version = Club_Migrate_Run(); }
        catch (Throwable $e) {
            Club_Log_Console('error', 'database merge failed', ['error' => $e->getMessage(), 'pid' => getmypid()]);
            exit(1);
        }
        if ($version !== DB_VERSION) {
            Club_Log_Console('error', 'database schema mismatch after merge', ['schema' => $version, 'expected' => DB_VERSION]);
            exit(1);
        }
        exit(0);
    case 'status':
        try { exit(cli_manage('status', '', array_slice($argv, 2))); }
        catch (InvalidArgumentException $e) { exit(cli_error($e->getMessage())); }
        catch (RuntimeException $e) { exit(cli_error($e->getMessage())); }
    default:
        fwrite(STDERR, 'Unknown parameter: '.$argv[1]."\n");
        exit(1);
}
