<?php require_once(TEST_ROOT.'/services.php');

function t_http_page($address, $path, $method = 'GET', $accept = 'application/activity+json', $body = null, $headers = []) {
    $ch = curl_init('http://'.$address.$path);
    curl_setopt_array($ch, [CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 5, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Accept: '.$accept], $headers)]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $reply = curl_exec($ch);
    if ($reply === false) throw new RuntimeException(curl_error($ch));
    $n = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), substr($reply, $n), strtolower(substr($reply, 0, $n))];
}

function t_controller_run() {
    global $config, $db;
    t_group('controller / public routes'); t_db_import();
    $dir = t_workspace('controller'); mkdir($dir.'/app');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/app', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $target = $dir.substr($file->getPathname(), strlen(APP_ROOT));
        if ($file->isDir()) mkdir($target); else copy($file->getPathname(), $target);
    }
    foreach (['index.php', 'cli.php'] as $file) copy(APP_ROOT.'/'.$file, $dir.'/'.$file);
    $config_file = $dir.'/config.php'; file_put_contents($config_file, '<?php $config = '.var_export($config, true).';');
    $socket = stream_socket_server('tcp://127.0.0.1:0'); $address = stream_socket_get_name($socket, false); fclose($socket);
    t_tls_keys($dir);
    $process = t_php_process(['-d', 'opcache.enable_cli=0', '-d', 'curl.cainfo='.$dir.'/ca.pem', '-S', $address, 'index.php'], $dir);
    try {
        t_wait_ready($process, function () use ($address) { $s = @stream_socket_client('tcp://'.$address, $errno, $msg, 0.1); if (!$s) return false; fclose($s); return true; });
        foreach ([['/club/newname/followers', 'GET', 404], ['/club/newname/missing', 'GET', 404], ['/club/newname', 'PUT', 405], ['/club/newname/outbox?page=wrong', 'GET', 400],
            ['/club/newname/inbox', 'POST', 400]] as $case) t_is(t_http_page($address, $case[0], $case[1])[0], $case[2], $case[1].' '.$case[0]);
        t_is((int)t_one('select count(*) from `clubs`'), 0, 'unhandled routes and empty inbox do not create clubs');
        list($status, $body, $headers) = t_http_page($address, '/.well-known/webfinger?resource=acct:test@LOCAL.example');
        t_is([$status, json_decode($body, true)['subject']], [200, 'acct:test@local.example'], 'WebFinger returns the canonical subject');
        t_ok(strpos($headers, 'application/jrd+json') !== false && strpos($headers, 'access-control-allow-origin: *') !== false, 'WebFinger media type and CORS');
        list($status, , $headers) = t_http_page($address, '/.well-known/webfinger?resource=acct:test@else.example');
        t_ok($status === 404 && strpos($headers, 'access-control-allow-origin: *') !== false, 'WebFinger errors also allow CORS');
        $node = json_decode(t_http_page($address, '/nodeinfo/2.0')[1], true);
        t_is([$node['software']['name'], $node['usage']['users']['total']], ['wxwclub', 1], 'NodeInfo uses a lowercase identifier and integer counts');
        t_is(t_http_page($address, '/club/test')[0], 200, 'actor is available');
        t_is(t_http_page($address, '/club/system')[0], 200, 'system signer is discoverable');
        t_is(t_http_page($address, '/', 'GET', 'text/html')[0], 200, 'home page is available');
        $cid = t_one("select `cid` from `clubs` where `name` = 'test'");
        t_exec("insert into `users` (`name`,`actor`,`inbox`,`shared_inbox`,`public_key`,`timestamp`) values ('reader','https://remote.example/user','https://remote.example/i','https://remote.example/i','',1)");
        for ($i = 0; $i <= 40; $i++) {
            if ($i) {
                t_exec("insert into `activities` (`uid`,`type`,`clubs`,`object`,`timestamp`) values (1,'Create','[]',:object,1)", [':object' => 'https://remote.example/p/'.$i]);
                t_exec("insert into `announces` (`uid`,`cid`,`activity`,`content`,`timestamp`) values (1,:cid,:id,'post',1)", [':cid' => $cid, ':id' => $i]);
            }
            if (!in_array($i, [0, 1, 20, 21, 40], true)) continue;
            $collection = json_decode(t_http_page($address, '/club/test/outbox')[1], true);
            foreach (['first' => 'next', 'last' => 'prev'] as $start => $follow) {
                $url = $collection[$start]; $objects = []; $seen = [];
                do {
                    if (isset($seen[$url])) throw new RuntimeException('Outbox pagination loop');
                    $seen[$url] = true; $page = json_decode(t_http_page($address, substr($url, strlen('https://local.example')))[1], true);
                    $items = array_column($page['orderedItems'], 'object');
                    if ($i) t_ok(count($items) > 0 && count($items) <= 20, 'link leads to a nonempty bounded page');
                    $objects = $start === 'first' ? array_merge($objects, $items) : array_merge($items, $objects);
                    $url = $page[$follow] ?? null;
                } while ($url !== null);
                $expected = []; for ($j = $i; $j >= 1; $j--) $expected[] = 'https://remote.example/p/'.$j;
                t_is($objects, $expected, $start.' traverses all '.$i.' equal-timestamp entries in order');
            }
        }
        foreach (['/club/test/outbox?page=true&min=1.1&max=1.2', '/club/test?min=wrong', '/club/test/outbox?max=1.2'] as $path)
            t_is(t_http_page($address, $path)[0], 400, 'invalid cursor '.$path);
        list($status, $body) = t_http_page($address, '/club/test', 'GET', 'text/html');
        t_ok($status === 200 && strpos($body, '?max=1.21') !== false && strpos($body, '?min=') === false, 'profile links to the next nonempty page');
        t_controller_csv($dir);
        $cli = escapeshellarg(PHP_BINARY).' '.escapeshellarg($dir.'/cli.php');
        foreach (['', ' --yes'] as $yes) {
            $out = []; exec($cli.' user delete https://remote.example/user'.$yes.' --json', $out, $code); $counts = json_decode(implode("\n", $out), true);
            t_is([$code, $counts['users'], $counts['activities'], $counts['announces']], [0, 1, 40, 40], 'user deletion counts match the saved posts');
        }
        t_exec("insert into `users` (`name`,`actor`,`inbox`,`shared_inbox`,`public_key`,`timestamp`) values ('other','https://notimport000.example/user','https://notimport000.example/i','https://notimport000.example/i','',1)");
        $out = []; exec($cli.' host delete import000.example --yes --json', $out, $code); $counts = json_decode(implode("\n", $out), true);
        t_is([$code, $counts['deleted'], $counts['left']], [0, 1, 0], 'host deletion counts only the exact authority');
        t_is((int)t_one("select count(*) from `users` where `actor` = 'https://notimport000.example/user'"), 1, 'similarly named host survives');
        t_controller_actor($dir, $address, $config);
        $offline = $config; $offline['mysql']['host'] = '127.0.0.1'; $offline['mysql']['database'] = 'wxwclub_offline_test'; $offline['mysql']['username'] = 'unavailable_user';
        file_put_contents($config_file, '<?php $config = '.var_export($offline, true).';');
        list($status, $body, $headers) = t_http_page($address, '/');
        t_is([$status, json_decode($body, true)['message'], strpos($headers, 'retry-after: 60') !== false], [503, 'Database unavailable', true], 'offline database returns a generic retryable response');
    } finally { proc_terminate($process); proc_close($process); }
}

function t_controller_actor($dir, $address, $config) {
    t_group('controller / signed actor lifecycle');
    $service = t_php_process([TEST_ROOT.'/fixtures/http-service.php', $dir, 'tunnel'], $dir);
    try {
        t_wait_ready($service, function () use ($dir) { return is_file($dir.'/port') && (int)file_get_contents($dir.'/port') > 0; });
        $config['node']['proxy'] = 'http://127.0.0.1:'.trim(file_get_contents($dir.'/port'));
        file_put_contents($dir.'/config.php', '<?php $config = '.var_export($config, true).';');
        foreach (['transport.example','alias.example'] as $host) t_exec('insert into `dns` (`host`,`ips`,`checked_at`) values (:host,:ips,:now)', [':host' => $host, ':ips' => '1.1.1.1', ':now' => time()]);
        $actor = 'https://transport.example/users/alice'; $inbox = $actor.'/inbox'; $key = file_get_contents(TEST_ROOT.'/fixtures/activitypub/keys/actor-private.pem');
        $doc = ['id' => $actor, 'type' => 'Person', 'preferredUsername' => 'alice', 'inbox' => $inbox, 'publicKey' => ['id' => $actor.'#main-key', 'owner' => $actor,
            'publicKeyPem' => file_get_contents(TEST_ROOT.'/fixtures/activitypub/keys/actor-public.pem')]];
        $jrd = ['subject' => 'acct:alice@alias.example', 'links' => [['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actor]]];
        $account = '/.well-known/webfinger?resource=acct%3Aalice%40'; $ok = '200 OK';
        $responses = ['transport.example/users/alice' => ['302 Found', "Location: ../profiles/./parts/../alice?doc=1\r\n", ''],
            'transport.example/profiles/alice?doc=1' => [$ok, '', json_encode($doc)], 'transport.example'.$account.'transport.example' => [$ok, '', json_encode($jrd)],
            'alias.example'.$account.'alias.example' => [$ok, '', json_encode($jrd)]];
        $save = function () use ($dir, &$responses) { file_put_contents($dir.'/replies.json', json_encode($responses)); };
        $send = function ($type) use ($actor, $address, &$key) {
            $body = json_encode(['actor' => $actor, 'type' => $type, 'id' => $actor.'/activities/'.$type,
                'object' => $type === 'Update' ? ['id' => $actor, 'type' => 'Person'] : 'https://local.example/club/test']);
            $date = gmdate('D, d M Y H:i:s T'); $digest = 'SHA-256='.base64_encode(hash('sha256', $body, true));
            openssl_sign("(request-target): post /inbox\nhost: local.example\ndate: ".$date."\ndigest: ".$digest, $raw, $key, OPENSSL_ALGO_SHA256);
            $sig = 'keyId="'.$actor.'#main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="'.base64_encode($raw).'"';
            return t_http_page($address, '/inbox', 'POST', 'application/activity+json', $body, ['Host: local.example', 'Date: '.$date, 'Digest: '.$digest, 'Signature: '.$sig])[0];
        };
        $save(); t_is($send('Follow'), 202, 'first signed Follow fetches the actor key');
        $row = t_row('select `name`,`webfinger`,`inbox`,`public_key` from `users` where `actor` = :actor', [':actor' => $actor]);
        t_ok($row && $row['name'] === 'alice@alias.example' && (int)$row['webfinger'] > 0 && $row['inbox'] === $inbox, 'canonical handle confirmed by both authorities');
        $doc['inbox'] = $inbox.'/new'; $doc['publicKey']['publicKeyPem'] = '';
        $responses['transport.example/users/alice'] = [$ok, '', json_encode($doc)]; $save(); t_exec('update `users` set `refresh` = 0 where `actor` = :actor', [':actor' => $actor]);
        t_is($send('Update'), 400, 'unusable replacement key is rejected');
        t_is(t_row('select `name`,`webfinger`,`inbox`,`public_key` from `users` where `actor` = :actor', [':actor' => $actor]), $row, 'failed refresh preserves the usable binding');
        $next = openssl_pkey_new(['private_key_bits' => 2048]); $doc['publicKey']['publicKeyPem'] = openssl_pkey_get_details($next)['key'];
        $responses['transport.example/users/alice'][2] = json_encode($doc); $save(); t_exec('update `users` set `refresh` = 0 where `actor` = :actor', [':actor' => $actor]);
        t_is($send('Update'), 202, 'verified profile update refreshes a rotated key and inbox');
        $key = $next; t_is($send('Follow'), 202, 'next request verifies with the rotated key');
        t_is((int)t_one('select count(distinct `target`) from `queues` where `target` in (:old,:new)', [':old' => $inbox, ':new' => $inbox.'/new']), 2, 'old and new Accepts retain their respective targets');
        $responses['transport.example'.$account.'transport.example'][2] = json_encode(['subject' => 'acct:renamed@alias.example'] + $jrd);
        $save(); t_exec('update `users` set `refresh` = 0 where `actor` = :actor', [':actor' => $actor]);
        t_is($send('Update'), 202, 'valid profile can refresh while handle confirmation is unavailable');
        t_is(t_one('select `name` from `users` where `actor` = :actor', [':actor' => $actor]), $row['name'], 'unconfirmed new subject preserves the confirmed handle');
        $requests = file($dir.'/signed', FILE_IGNORE_NEW_LINES); $local = t_one("select `public_key` from `clubs` where `name` = 'system'");
        t_ok(count($requests) >= 5, 'actor and both handle authorities received requests');
        foreach ($requests as $request) {
            list($host, $path, $sig, $date) = json_decode($request, true); $fields = ActivityPub_Signature_Parse($sig);
            t_is(openssl_verify("(request-target): get ".$path."\nhost: ".$host."\ndate: ".$date, base64_decode($fields['signature']), $local, OPENSSL_ALGO_SHA256), 1, 'signature matches the received GET');
        }
        t_controller_dns($dir, $config);
    } finally { proc_terminate($service); proc_close($service); }
}

function t_controller_dns($dir, $config) {
    $config['dns']['resolver'] = [['url' => 'https://transport.example/dns', 'ip' => ['1.1.1.1']]];
    file_put_contents($dir.'/config.php', '<?php $config = '.var_export($config, true).';');
    $host = 'records.example'; $path = '/dns?name='.$host.'&type=';
    $cli = escapeshellarg(PHP_BINARY).' -d '.escapeshellarg('curl.cainfo='.$dir.'/ca.pem').' '.escapeshellarg($dir.'/cli.php').' dns refresh '.$host.' --json';
    $positive = ['Status' => 0, 'Answer' => [['type' => 1, 'data' => '203.0.113.5']]]; $empty = ['Status' => 3]; $failed = ['Status' => 2];
    $before = count(file($dir.'/requests')); $cache = '';
    foreach ([['resolved', $positive, $empty], ['failed', $failed, $empty], ['failed', $positive, $empty, '206 Partial Content'], ['empty', $empty, $empty], ['deferred', null, null]] as $case) {
        list($state, $a, $aaaa) = $case; $status = $case[3] ?? '200 OK';
        if ($state === 'deferred') t_exec('update `dns` set `lock_until` = :until where `host` = :host', [':until' => time() + 30, ':host' => $host]);
        $replies = []; foreach (['A' => $a, 'AAAA' => $aaaa] as $type => $answer) $replies['transport.example'.$path.$type] = [$status, '', json_encode($answer)];
        file_put_contents($dir.'/replies.json', json_encode($replies));
        $out = []; exec($cli, $out, $code); $result = json_decode(implode("\n", $out), true);
        t_is([$result['state'], $result['deferred'], $code], [$state, $state === 'deferred', in_array($state, ['failed','deferred'], true) ? 2 : 0], 'forced DNS refresh reports '.$state);
        if ($state === 'resolved') $cache = '203.0.113.5'; elseif ($state === 'empty') $cache = '';
        t_is($result['cache']['ips'], $cache, 'DNS cache after '.$state);
    }
    $queries = array_slice(file($dir.'/requests', FILE_IGNORE_NEW_LINES), $before);
    t_is($queries, array_merge(...array_fill(0, 4, ['GET '.$path.'A', 'GET '.$path.'AAAA'])), 'four lookups reach both families and the contended lookup sends nothing');
}

function t_controller_csv($dir) {
    $reason = "原样 \\ \"note\"\nsecond line\\"; $file = $dir.'/bans.csv';
    $csv = fopen($file, 'wb');
    foreach ([['target','type','clubs','reason'], ['https://remote.example/@reader','actor','Test,test',$reason], ['other.example','host','','next']] as $row) fputcsv($csv, $row, ',', '"', "\0");
    fclose($csv);
    $cli = escapeshellarg(PHP_BINARY).' '.escapeshellarg($dir.'/cli.php').' ban ';
    exec($cli.'import '.escapeshellarg($file).' --yes --json', $out, $code);
    t_is($code, 0, 'CSV import succeeds');
    t_is(t_one('select `reason` from `bans` where `target` = :target', [':target' => 'https://remote.example/@reader']), $reason, 'CSV import preserves quotes, newlines and trailing backslash');
    $export = $dir.'/export.csv';
    exec($cli.'export > '.escapeshellarg($export), $out, $code);
    $in = fopen($export, 'rb'); $rows = [];
    while (($row = fgetcsv($in, 0, ',', '"', "\0")) !== false) $rows[] = $row;
    fclose($in);
    t_is([$code, $rows[1][3], $rows[2][0]], [0, $reason, 'other.example'], 'export preserves field and following row');
    $out = []; exec($cli.'import '.escapeshellarg($export).' --yes --json', $out, $code);
    $result = json_decode(implode("\n", $out), true); t_is([$code, $result['existing'], $result['added']], [0, 2, 0], 'reimport reports existing bans');
    t_exec('update `bans` set `reason` = :reason where `target` = :target', [':reason' => 'local note', ':target' => 'other.example']);
    $out = []; exec($cli.'import '.escapeshellarg($export).' --yes --json', $out, $code);
    t_is(t_one('select `reason` from `bans` where `target` = :target', [':target' => 'other.example']), 'local note', 'import preserves the local reason');
    file_put_contents($file, "target,reason\nfresh.example,first\nlater.example,".str_repeat('x', 256)."\n");
    $out = []; exec($cli.'import '.escapeshellarg($file).' --yes 2>&1', $out, $code);
    t_ok($code !== 0 && strpos(implode("\n", $out), 'line 3') !== false, 'invalid CSV identifies the starting line');
    t_is((int)t_one("select count(*) from `bans` where `target` = 'fresh.example'"), 0, 'validation precedes writing any row');
    file_put_contents($file, "target\n".str_repeat("repeat.example\n", 50001));
    $out = []; exec($cli.'import '.escapeshellarg($file).' --yes 2>&1', $out, $code);
    t_ok($code !== 0 && strpos(implode("\n", $out), '50000') !== false, 'duplicate input records consume the row budget');
    $batch = "target\n"; for ($i = 0; $i <= 250; $i++) $batch .= sprintf("import%03d.example\n", $i);
    file_put_contents($file, $batch);
    foreach (['import000.example','import250.example'] as $host) {
        $actor = 'https://'.$host.':443/user';
        t_exec("insert into `users` (`name`,`actor`,`inbox`,`shared_inbox`,`public_key`,`timestamp`) values ('reader',:actor,:inbox,:shared,'',1)",
            [':actor' => $actor, ':inbox' => $actor.'/inbox', ':shared' => $actor.'/inbox']);
        Club_Group_Follow($actor, 'test', true);
    }
    t_exec("create trigger `pause_import` before insert on `bans` for each row begin if new.`target` = 'import250.example' then signal sqlstate '45000' set message_text = 'batch boundary'; end if; end");
    try {
        $out = []; exec($cli.'import '.escapeshellarg($file).' --yes 2>&1', $out, $code);
        t_ok($code !== 0 && strpos(implode("\n", $out), '1 committed batches') !== false, 'partial import reports its completed batch');
        t_is((int)t_one("select count(*) from `bans` where `target` like 'import%.example'"), 250, 'the first batch survives an interruption');
        t_is((int)t_one('select count(*) from `followers`'), 2, 'follows are intact until every ban is saved');
    } finally { t_exec('drop trigger `pause_import`'); }
    foreach ([false, true] as $apply) {
        $out = []; exec($cli.'import '.escapeshellarg($file).($apply ? ' --yes' : '').' --json', $out, $code); $result = json_decode(implode("\n", $out), true);
        t_is([$code, $result['added'], $result['existing'], $result[$apply ? 'follows_dropped' : 'follows']], [0, 1, 250, 2], 'resumed import reports matching preview and execution');
    }
    t_is((int)t_one('select count(*) from `followers`'), 0, 'resuming detaches follows for both saved batches');
}

t_controller_run();
