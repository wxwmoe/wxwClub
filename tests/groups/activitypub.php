<?php

$t_ap_keys = ['status', 'stored', 'follower_created', 'follower_removed', 'announce_created', 'announce_revoked', 'content_updated', 'poll_updated', 'delivery_enqueued', 'relayed', 'actor_deleted'];

function t_ap_dir() {
    return TEST_ROOT.'/fixtures/activitypub';
}

function t_ap_list() {
    $all = [];
    foreach (glob(t_ap_dir().'/*', GLOB_ONLYDIR) as $dir) {
        if (basename($dir) === 'keys') continue;
        $fixtures = [];
        foreach (glob($dir.'/*.php') as $file) $fixtures[basename($file, '.php')] = require($file);
        uksort($fixtures, function ($a, $b) use ($fixtures) { $order = $fixtures[$a]['seq'] - $fixtures[$b]['seq']; return $order ?: strcmp($a, $b); });
        $all[basename($dir)] = $fixtures;
    }
    ksort($all);
    return $all;
}

function t_ap_body($fixture) {
    return $fixture['request']['body'];
}

function t_ap_activity($fixture) {
    $jsonld = json_decode(t_ap_body($fixture), 1);
    return count($jsonld) === 1 && isset($jsonld[0]) && is_array($jsonld[0]) ? $jsonld[0] : $jsonld;
}

function t_ap_club($fixture) {
    return preg_match('#^/club/([^/]+)/inbox$#', $fixture['request']['path'], $m) ? $m[1] : null;
}

function t_ap_target($fixture, $activity) {
    if (($club = t_ap_club($fixture)) !== null) return $club;
    $object = isset($activity['object']) ? $activity['object'] : '';
    $name = Club_Group_From_Object($object);
    if ($name === false && is_array($object) && isset($object['object'])) $name = Club_Group_From_Object($object['object']);
    return $name === false ? '' : $name;
}

function t_ap_run() {
    global $t_pass, $t_fail, $t_ap_keys;
    foreach (t_ap_list() as $software => $fixtures) {
        t_group('activitypub / '.$software);
        t_ap_seed($fixtures);
        foreach ($fixtures as $name => $fixture) {
            foreach (array_keys($fixture['expect']) as $key) if (!in_array($key, $t_ap_keys, true)) t_ok(false, $software.'/'.$name.': unknown expect key "'.$key.'"');
            list($output, $code) = t_ap_spawn($software, $name);
            if (preg_match('/^#counts (\d+) (\d+)$/m', $output, $m)) {
                $t_pass += (int)$m[1]; $t_fail += (int)$m[2];
                $output = trim(preg_replace('/^#counts .*$/m', '', $output));
            } else {
                $t_fail++;
                echo '  FAIL  ', $software, '/', $name, ": replay did not report a result (exit ", $code, ")\n";
            }
            if ($output !== '') echo $output, "\n";
        }
    }
}

function t_ap_spawn($software, $name) {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(APP_ROOT.'/cli.php').' test replay '.escapeshellarg($software).' '.escapeshellarg($name);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return ['', -1];
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [rtrim($output, "\r\n"), proc_close($process)];
}

function t_ap_seed($fixtures) {
    global $config;
    t_db_import();
    $public = file_get_contents(t_ap_dir().'/keys/actor-public.pem');
    $private = file_get_contents(t_ap_dir().'/keys/actor-private.pem');
    foreach (['test', $config['club']['system-name']] as $club) t_exec('insert into `clubs`(`name`,`public_key`,`private_key`,`timestamp`)'.
        ' values (:name, :public, :private, :now)', [':name' => $club, ':public' => $public, ':private' => $private, ':now' => time()]);
    $actors = [];
    foreach ($fixtures as $fixture) {
        $actor = ActivityPub_Object_Id(t_ap_activity($fixture)['actor'] ?? '');
        if ($actor !== '') $actors[$actor] = isset($fixture['key']) ? $fixture['key'] : 'actor';
    }
    foreach ($actors as $actor => $key) {
        $host = parse_url($actor, PHP_URL_HOST);
        t_exec('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`,`refresh`) values (:name, :actor, :inbox, :public, :shared, :now, :now)',
            [':name' => basename($actor).'@'.$host, ':actor' => $actor, ':inbox' => $actor.'/inbox', ':public' => file_get_contents(t_ap_dir().'/keys/'.$key.'-public.pem'),
                ':shared' => 'https://'.$host.'/inbox', ':now' => time()]);
    }
    foreach ($fixtures as $fixture) if (isset($fixture['ban'])) t_exec('insert ignore into `bans`(`target`,`type`,`timestamp`) values (:target, :type, :now)',
        [':target' => $fixture['ban']['target'], ':type' => $fixture['ban']['type'], ':now' => time()]);
    t_exec('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`,`refresh`)'.
        ' values (:name, :actor, :inbox, :public, :shared, :now, :now)', [':name' => 'bob@other.example', ':actor' => 'https://other.example/users/bob',
            ':inbox' => 'https://other.example/users/bob/inbox', ':public' => $public, ':shared' => 'https://other.example/inbox', ':now' => time()]);
    t_exec('insert into `followers`(`cid`,`uid`,`timestamp`) select `c`.`cid`, `u`.`uid`, :now from `clubs` `c`, `users` `u`'.
        ' where `c`.`name` = \'test\' and `u`.`actor` = :actor', [':actor' => 'https://other.example/users/bob', ':now' => time()]);
}

function t_ap_replay($software, $name) {
    $file = t_ap_dir().'/'.$software.'/'.$name.'.php';
    if (!is_file($file)) return t_ok(false, $software.'/'.$name.': fixture not found');
    $fixture = require($file);
    $body = t_ap_body($fixture);
    $club = t_ap_club($fixture);
    $activity = t_ap_activity($fixture);
    $actor = ActivityPub_Object_Id($activity['actor'] ?? '');
    $object = ActivityPub_Object_Id(isset($activity['object']) ? $activity['object'] : '');
    $label = $software.'/'.$name;

    $target = t_ap_target($fixture, $activity);
    $_SERVER = t_ap_server($fixture, $body, $actor);
    $before = t_ap_state($actor, $target, $object, $body);
    ob_start();
    Club_Inbox_Receive($club, $body);
    $reply = json_decode(ob_get_clean(), 1);
    $after = t_ap_state($actor, $target, $object, $body);

    foreach ($fixture['expect'] as $key => $want) switch ($key) {
        case 'status': t_is(isset($reply['code']) ? (int)$reply['code'] : 0, $want, $label.' status'); break;
        case 'stored':
            if ($want === false) { t_is($after['activities'], $before['activities'], $label.' stores no activity'); break; }
            t_is($after['activities'] - $before['activities'], 1, $label.' stores one activity');
            t_is($after['newest'], $want, $label.' stored activity type');
            break;
        case 'follower_created': t_is($before['followers'] === 0 && $after['followers'] === 1, $want, $label.' follower created'); break;
        case 'follower_removed': t_is($before['followers'] === 1 && $after['followers'] === 0, $want, $label.' follower removed'); break;
        case 'announce_created': t_is($after['announces'] > $before['announces'], $want, $label.' announce created'); break;
        case 'announce_revoked': t_is($before['announces'] > 0 && $after['announces'] === 0, $want, $label.' announce revoked'); break;
        case 'content_updated': t_is($after['updated'] > $before['updated'], $want, $label.' content revision advanced'); break;
        case 'poll_updated': t_is($after['polled'] > $before['polled'], $want, $label.' poll revision advanced'); break;
        case 'delivery_enqueued': t_is($after['queues'] > $before['queues'], $want, $label.' delivery enqueued'); break;
        case 'relayed': t_is($after['relayed'] > $before['relayed'], $want, $label.' original payload relayed verbatim'); break;
        case 'actor_deleted': t_is($before['user'] === 1 && $after['user'] === 0, $want, $label.' actor deleted'); break;
    }
}

function t_ap_server($fixture, $body, $actor) {
    global $config;
    $request = $fixture['request'];
    $server = ['REQUEST_METHOD' => $request['method'], 'REQUEST_URI' => $request['path'], 'HTTP_HOST' => $config['base'], 'CONTENT_LENGTH' => (string)strlen($body)];
    foreach (isset($request['headers']) ? $request['headers'] : [] as $key => $value) {
        $key = strtoupper(str_replace('-', '_', $key));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH']) ? $key : 'HTTP_'.$key] = $value;
    }
    $sign = isset($request['sign']) ? $request['sign'] : true;
    if ($sign === false) return $server;
    if (!is_array($sign)) $sign = [];
    $now = isset($sign['date']) ? $sign['date'] : time();
    $headers = isset($sign['headers']) ? $sign['headers'] : ['(request-target)', 'host', 'date', 'digest'];
    $stamps = ['created' => isset($sign['created']) ? $sign['created'] : $now, 'expires' => isset($sign['expires']) ? $sign['expires'] : $now + 300];
    $server['HTTP_DATE'] = gmdate('D, d M Y H:i:s T', $now);
    $server['HTTP_DIGEST'] = 'SHA-256='.base64_encode(hash('sha256', isset($sign['body']) ? $sign['body'] : $body, true));
    $lines = [];
    foreach ($headers as $header) {
        if ($header === '(request-target)') $lines[] = $header.': '.strtolower($request['method']).' '.$request['path'];
        elseif ($header === '(created)' || $header === '(expires)') $lines[] = $header.': '.$stamps[trim($header, '()')];
        else {
            $key = strtoupper(str_replace('-', '_', $header));
            if (!in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) $key = 'HTTP_'.$key;
            $lines[] = $header.': '.(isset($server[$key]) ? $server[$key] : '');
        }
    }
    openssl_sign(implode("\n", $lines), $signature, file_get_contents(t_ap_dir().'/keys/actor-private.pem'), isset($sign['hash']) ? $sign['hash'] : OPENSSL_ALGO_SHA256);
    $header = 'keyId="'.(isset($sign['keyId']) ? $sign['keyId'] : $actor.'#main-key').'",algorithm="'.(isset($sign['algorithm']) ? $sign['algorithm'] : 'rsa-sha256').
        '",headers="'.implode(' ', $headers).'",signature="'.(isset($sign['signature']) ? $sign['signature'] : base64_encode($signature)).'"';
    foreach ($stamps as $name => $stamp) if (in_array('('.$name.')', $headers)) $header .= ','.$name.'='.$stamp;
    $server['HTTP_SIGNATURE'] = $header.(isset($sign['params']) ? $sign['params'] : '');
    return $server;
}

function t_ap_state($actor, $club, $object, $body) {
    return [
        'followers' => (int)t_one('select count(*) from `followers` `f` join `clubs` `c` on f.cid = c.cid join `users` `u` on f.uid = u.uid'.
            ' where c.name = :club and u.actor = :actor', [':club' => $club, ':actor' => $actor]),
        'queues' => (int)t_one('select count(*) from `queues`'),
        'activities' => (int)t_one('select count(*) from `activities`'),
        'newest' => (string)t_one('select `type` from `activities` order by `id` desc limit 1'),
        'user' => (int)t_one('select count(*) from `users` where `actor` = :actor', [':actor' => $actor]),
        'updated' => (int)t_one('select coalesce(max(`updated`), 0) from `activities` where `object` = :object', [':object' => $object]),
        'polled' => (int)t_one('select coalesce(max(`polled`), 0) from `activities` where `object` = :object', [':object' => $object]),
        'announces' => (int)t_one('select count(*) from `announces` `a` join `activities` `v` on a.activity = v.id where v.object = :object', [':object' => $object]),
        'relayed' => (int)t_one('select count(*) from `tasks` where `jsonld` = :body', [':body' => $body])
    ];
}
