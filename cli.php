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
  php cli.php user cleanup [--yes]
  php cli.php user fetch <handle|actor-url>
  php cli.php user groups <handle|actor-url> [--json]
  php cli.php follow add|remove <handle|actor-url> <club>
  php cli.php ban add <handle|actor-url|host> [--club CLUB,CLUB] [--reason TEXT] [--yes]
  php cli.php ban remove <handle|actor-url|host> [--club CLUB,CLUB]
  php cli.php ban list [--type actor|host] [--club CLUB] [--limit 50] [--json]
  php cli.php ban export > bans.csv
  php cli.php ban import <file.csv> [--yes]
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
    // 确认过的那行拥有这个 handle：域名的 WebFinger 答复说了算，而 Club_Actor_Confirm 会把别人对同一个 handle 的旧声明置 0。一个确认过的都没有才是真歧义
    $pdo = $db->prepare('select * from `users` where `name` = :name order by `webfinger` desc, `uid` limit 2'); $pdo->execute([':name' => $name]);
    $rows = $pdo->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1 && !(int)$rows[0]['webfinger']) throw new RuntimeException('more than one cached actor uses this handle; pass the actor URL');
    return $rows ? $rows[0] : null;
}

// --club 收逗号分隔的群组名。null 是没给（全站），false 是给了但里面有本站没有的群组 —— 打错一个字就该报错，而不是悄悄封了个不存在的范围
function cli_ban_clubs($input) {
    global $db;
    if (!isset($input)) return null;
    $clubs = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$input)), 'strlen')));
    if (!$clubs) return false;
    $names = []; $params = [];
    foreach ($clubs as $i => $club) { $names[] = ':c'.$i; $params[':c'.$i] = $club; }
    $pdo = $db->prepare('select `name` from `clubs` where `name` in ('.implode(',', $names).')');
    $pdo->execute($params);
    return count($found = $pdo->fetchAll(PDO::FETCH_COLUMN, 0)) === count($clubs) ? $found : false;
}

// 封禁目标。handle 先走 WebFinger 解析成 actor URL —— 那是本站的身份键，handle 只是它此刻的标签。导入时 $resolve 关掉：一份上万行的名单不能每行出一次网
function cli_ban_target($input, $resolve = true) {
    $input = trim((string)$input);
    if (strpos($input, '@') !== false) {
        if (!$resolve) return false;
        $input = Club_Actor_Resolve($input) ?: '';
    }
    return Club_Ban_Target($input);
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
        if (!($user = Club_Actor_Fetch($actor, $document))) return cli_error('could not fetch actor');
        // 已经确认过的行，拉取那边不会再自动确认（它只认第一次落库），所以这条命令每次都补一次：手工点名一个 actor 时要的就是「现在到底归谁」
        Club_Actor_Confirm($actor, $document);
        $pdo = $db->prepare('select * from `users` where `uid` = :uid'); $pdo->execute([':uid' => $user['uid']]); $user = $pdo->fetch(PDO::FETCH_ASSOC);
        $data = ['uid' => (int)$user['uid'], 'name' => $user['name'], 'actor' => $user['actor'], 'inbox' => $user['inbox'], 'shared_inbox' => $user['shared_inbox'],
            'timestamp' => (int)$user['timestamp'], 'refresh' => (int)$user['refresh'], 'webfinger' => (int)$user['webfinger'], 'public_key_sha256' => hash('sha256', $user['public_key'])];
        $data['timestamp_text'] = cli_time($user['timestamp']); $data['refresh_text'] = cli_time($user['refresh']);
        $data['webfinger_text'] = $user['webfinger'] ? cli_time($user['webfinger']) : 'never';
        cli_emit($data, ['uid:          '.$user['uid'], 'name:         '.$user['name'], 'actor:        '.$user['actor'], 'inbox:        '.$user['inbox'],
            'shared_inbox: '.$user['shared_inbox'], 'created:      '.$data['timestamp_text'], 'refreshed:    '.$data['refresh_text'],
            'confirmed:    '.$data['webfinger_text']]);
        return 0;
    }

    if ($resource === 'user' && $action === 'cleanup') {
        $yes = cli_flag($args, '--yes');
        if ($args) return cli_error('usage: php cli.php user cleanup [--yes]');
        $unused = (int)$db->query('select count(*) from `users` where not exists (select 1 from `followers` where `followers`.`uid` = `users`.`uid`)' .
            ' and not exists (select 1 from `activities` where `activities`.`uid` = `users`.`uid`)')->fetchColumn();
        if (!$yes) {
            cli_emit(['preview' => true, 'users' => $unused], ['Matched '.$unused.' unused user(s).', 'Run again with --yes to clean up.']);
            return $unused ? 2 : 0;
        }
        $deleted = Club_Actor_Cleanup();
        cli_emit(['deleted' => $deleted], ['Cleaned up '.$deleted.' unused user(s).']);
        return 0;
    }

    if ($resource === 'ban' && $action === 'add') {
        $yes = cli_flag($args, '--yes'); $reason = cli_option($args, '--reason'); $clubs = cli_ban_clubs(cli_option($args, '--club'));
        if (count($args) !== 1 || $clubs === false) return cli_error('usage: php cli.php ban add <handle|actor-url|host> [--club CLUB,CLUB] [--reason TEXT] [--yes]');
        if (!($ban = cli_ban_target($args[0]))) return cli_error('could not read a target actor or host from '.$args[0]);
        list($type, $target) = $ban;
        // 预览要按「执行之后的作用域」来数：Club_Ban_Add 会跟库里已有的取并集，只按这次传的 --club 数的话，
        // 原本封了 test 的目标再来一条 --club other，预览只报 other 那一份，执行却把两个群组的关注都解除
        $pdo = $db->prepare('select `clubs` from `bans` where `target` = :target');
        $pdo->execute([':target' => $target]);
        $effective = ($old = $pdo->fetch(PDO::FETCH_COLUMN, 0)) === false ? $clubs
            : Club_Ban_Scope(isset($old) ? (json_decode($old, 1) ?: []) : null, $clubs);
        $follows = Club_Ban_Detach([$target], $type, $effective, true);
        $scope = isset($effective) ? ' in '.implode(', ', $effective) : ' site wide';
        if (!$yes) {
            cli_emit(['preview' => true, 'target' => $target, 'type' => $type, 'clubs' => $clubs, 'follows' => $follows],
                ['Would ban '.$type.' '.$target.$scope.'.', 'Matched '.$follows.' follow(s) to drop.', 'Run again with --yes to apply.']);
            return 0;
        }
        $dropped = Club_Ban_Add($target, $type, $clubs, $reason);
        cli_emit(['target' => $target, 'type' => $type, 'clubs' => $effective, 'follows_dropped' => $dropped],
            ['Banned '.$type.' '.$target.$scope.'.', 'Dropped '.$dropped.' follow(s).']);
        return 0;
    }

    if ($resource === 'ban' && $action === 'remove') {
        $clubs = cli_ban_clubs(cli_option($args, '--club'));
        if (count($args) !== 1 || $clubs === false) return cli_error('usage: php cli.php ban remove <handle|actor-url|host> [--club CLUB,CLUB]');
        if (!($ban = cli_ban_target($args[0]))) return cli_error('could not read a target actor or host from '.$args[0]);
        // 解封不重建关注关系：本站没有原始 Follow，伪造不了 Accept，对方要回来得自己再关注一次
        $state = Club_Ban_Remove($ban[1], $clubs);
        if ($state === 'absent') return cli_error('target is not banned', 2);
        if ($state === 'site-wide') return cli_error($ban[1].' is banned across every club; lift the whole ban and add it back per club', 2);
        if ($state === 'host-wide') return cli_error($ban[1].' is not banned on its own; its host is, lift the ban on the host instead', 2);
        cli_emit(['target' => $ban[1], 'clubs' => $clubs, 'state' => $state],
            [($state === 'narrowed' ? 'Narrowed' : 'Removed').' the ban on '.$ban[1].(isset($clubs) ? ' in '.implode(', ', $clubs) : '').'.',
                'Existing follows are not restored; the target has to follow again.']);
        return 0;
    }

    if ($resource === 'ban' && $action === 'list') {
        $limit = (int)cli_option($args, '--limit', 50); $type = cli_option($args, '--type'); $club = cli_option($args, '--club');
        if ($args || $limit < 1 || $limit > 200 || (isset($type) && !in_array($type, ['actor', 'host'], true)))
            return cli_error('usage: php cli.php ban list [--type actor|host] [--club CLUB] [--limit 50]');
        $where = isset($type) ? ' where `type` = :type' : '';
        // 作用域是一列 JSON，过滤只能在 PHP 这边做，所以按群组筛时不能先 limit 再筛 —— 最新的那 limit 条要是都不属于这个群组，更旧的匹配项根本读不到，结果会假装是空的。
        // 不筛群组时照旧交给 SQL 限量；筛群组时整张表读进来再切，bans 是运维规模的表，一条手动命令不值得为它加索引或者拿 like 去猜 JSON 的形状
        $pdo = $db->prepare('select `target`, `type`, `clubs`, `reason`, `timestamp` from `bans`'.$where.' order by `timestamp` desc, `target`'.(isset($club) ? '' : ' limit '.$limit));
        $pdo->execute(isset($type) ? [':type' => $type] : []); $rows = [];
        foreach ($pdo->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scope = isset($row['clubs']) ? (json_decode($row['clubs'], 1) ?: []) : null;
            if (isset($club) && (!isset($scope) || !in_array($club, $scope, true))) continue;
            $row['clubs'] = isset($scope) ? implode(',', $scope) : '*';
            $row['banned'] = cli_time($row['timestamp']);
            if (count($rows) < $limit) $rows[] = $row;
        }
        cli_emit(['items' => $rows], cli_table($rows, ['target' => 'TARGET', 'type' => 'TYPE', 'clubs' => 'CLUBS', 'reason' => 'REASON', 'banned' => 'BANNED']));
        return 0;
    }

    // 导出的就是导入认得的那份 CSV，两边同一组列，来回一趟不丢东西。写到 stdout，落盘交给 shell 的重定向
    if ($resource === 'ban' && $action === 'export') {
        if ($args) return cli_error('usage: php cli.php ban export > bans.csv');
        $pdo = $db->query('select `target`, `type`, `clubs`, `reason` from `bans` order by `target` collate ascii_bin');
        // 四个参数都显式传：8.4 起省掉 $escape 会发 Deprecated，而这条命令的 stdout 就是文件本身，任何一行提示都会混进 CSV，导入时读到的表头就成了那句提示。
        // escape 保持 PHP 的默认反斜杠而不是空串 —— 空串要 7.4 才认，而这个仓库的语法下限是 7.3
        $out = fopen('php://output', 'w');
        fputcsv($out, ['target', 'type', 'clubs', 'reason'], ',', '"', '\\');
        foreach ($pdo->fetchAll(PDO::FETCH_ASSOC) as $row)
            fputcsv($out, [$row['target'], $row['type'], isset($row['clubs']) ? implode(',', json_decode($row['clubs'], 1) ?: []) : '', (string)$row['reason']], ',', '"', '\\');
        fclose($out);
        return 0;
    }

    // 导入。domain 列当 target 的别名，社区里流传的那些封禁名单就能直接喂进来；已经在库里的靠 Club_Ban_Add 的并集收敛，不会把已有的作用域缩小
    if ($resource === 'ban' && $action === 'import') {
        $yes = cli_flag($args, '--yes');
        if (count($args) !== 1) return cli_error('usage: php cli.php ban import <file.csv> [--yes]');
        if (!is_readable($args[0]) || !($in = fopen($args[0], 'r'))) return cli_error('cannot read '.$args[0]);
        if (!($head = fgetcsv($in, 0, ',', '"', '\\'))) return cli_error('the file has no header row');
        // 空单元格在 fgetcsv 里是 null，直接 trim 在 8.4 上是一条 Deprecated
        $head = array_flip(array_map('strtolower', array_map('trim', array_map('strval', $head))));
        $column = isset($head['target']) ? $head['target'] : (isset($head['domain']) ? $head['domain'] : null);
        if (!isset($column)) return cli_error('the header needs a target or domain column');
        $rows = []; $invalid = 0; $cap = 50000;
        while (($line = fgetcsv($in, 0, ',', '"', '\\')) !== false) {
            if (count($rows) >= $cap) { fclose($in); return cli_error('refusing to import more than '.$cap.' rows in one run'); }
            if (!isset($line[$column]) || trim($line[$column]) === '') continue;
            // 目标的形状说了算，CSV 里那一列 type 只是记录：带 scheme 的值不会因为那里写着 host 就变成 host
            if (!($ban = cli_ban_target($line[$column], false))) { $invalid++; continue; }
            // 空的 clubs 格就是「全站」，那正是导出写出来的形状；当成错误的话，导出再导入会把所有全站封禁判成无效跳过，往返直接丢一大半
            $cell = isset($head['clubs']) && isset($line[$head['clubs']]) ? trim($line[$head['clubs']]) : '';
            $scope = $cell === '' ? null : cli_ban_clubs($cell);
            if ($scope === false) { $invalid++; continue; }
            $reason = isset($head['reason']) && isset($line[$head['reason']]) ? trim($line[$head['reason']]) : '';
            if ($reason === '' && isset($head['public_comment']) && isset($line[$head['public_comment']])) $reason = trim($line[$head['public_comment']]);
            // 同一个 target 在文件里出现多次是合并不是覆盖，否则前面几行的作用域和理由会被最后一行悄悄吃掉
            // reason 那列是 255 个字符不是 255 字节，但截断不走 mbstring：全仓库别处都用 preg 的 /u 数字符，把 CLI 跑在容器外时扩展集不由 Dockerfile 说了算。
            // 不是合法 UTF-8 时退回按字节截，那种值本来也写不进 utf8mb4 的列
            $key = $ban[1];
            if ($reason === '') $reason = null;
            elseif (preg_match('/^.{0,255}/us', $reason, $cut)) $reason = $cut[0];
            else $reason = substr($reason, 0, 255);
            // 空的理由格是「这份名单没说」，不是「把原来那条清掉」
            if (isset($rows[$key])) { $scope = Club_Ban_Scope($rows[$key][2], $scope); $reason = isset($rows[$key][3]) ? $rows[$key][3] : $reason; }
            $rows[$key] = [$ban[1], $ban[0], $scope, $reason];
        }
        fclose($in);
        $existing = 0; $added = 0; $groups = [];
        $seen = $db->prepare('select `clubs` from `bans` where `target` = :target');
        foreach ($rows as $row) {
            $seen->execute([':target' => $row[0]]);
            $old = $seen->fetch(PDO::FETCH_COLUMN, 0);
            if ($old === false) $added++;
            // 已经在库里的也要进扫描：执行时 Club_Ban_Add 会把作用域取并集，扩大之后照样解除关注关系。
            // 预览要是跳过它们就会报「Matched 0 follow(s)」而实际删掉一批 —— 预览和执行必须扫同一批、用同一个合并后的作用域
            else { $existing++; $row[2] = Club_Ban_Scope(isset($old) ? (json_decode($old, 1) ?: []) : null, $row[2]); }
            // 同类型同作用域的归成一组，整组一次扫描：一个目标扫一遍 users 的话，一份上万条的名单就是上万遍全表
            $groups[$row[1].'|'.(isset($row[2]) ? implode(',', $row[2]) : '*')][] = $row[0];
        }
        $follows = 0;
        foreach ($groups as $key => $targets) {
            list($type, $scope) = explode('|', $key, 2);
            $follows += Club_Ban_Detach($targets, $type, $scope === '*' ? null : explode(',', $scope), !$yes);
        }
        if (!$yes) {
            cli_emit(['preview' => true, 'added' => $added, 'existing' => $existing, 'invalid' => $invalid, 'follows' => $follows],
                ['Would add '.$added.' ban(s), '.$existing.' already present, '.$invalid.' unusable row(s).', 'Matched '.$follows.' follow(s) to drop.', 'Run again with --yes to apply.']);
            return 0;
        }
        // 已经在库里的也过一遍 Club_Ban_Add：作用域取并集，导入一份群组名单不会把原来的全站封禁悄悄缩小
        foreach ($rows as $row) Club_Ban_Add($row[0], $row[1], $row[2], $row[3]);
        Club_Log_Event('info', 'bans imported by cli', ['added' => $added, 'existing' => $existing, 'invalid' => $invalid, 'follows dropped' => $follows]);
        cli_emit(['added' => $added, 'existing' => $existing, 'invalid' => $invalid, 'follows_dropped' => $follows],
            ['Added '.$added.' ban(s), '.$existing.' already present, '.$invalid.' unusable row(s).', 'Dropped '.$follows.' follow(s).']);
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
        if ($state === 'banned') return cli_error($user['actor'].' is banned; lift the ban before adding the follow back', 2);
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
    case 'ban':
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
