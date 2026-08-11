<?php

/* 协议兼容性重放。fixtures/activitypub/<软件>/<场景>.php 每个文件是一条真实报文加它必须产生的语义结果，这个文件负责发现、排序、执行和比对。
 *
 * 报文是从线上 inbox 抓下来的原始字节，只把域名和用户名改写成了 example 域，其余一个字节没动 —— 兼容性的风险在报文形状里，形状不能被 json_decode 再编码一遍磨掉。
 * 签名不能照抄：抓下来的 Signature 是对端用它自己的私钥、按当时的 Date 签的，本站既没有那把私钥，Date 也早就过了时效窗口。所以每次重放都用仓库里的测试密钥现签一遍，
 * users.public_key 里放的就是配套的公钥，这样验签、Digest、时效那一整段走的是真代码而不是被绕过去。fixture 想验失败分支就自己写 sign。
 *
 * 一条 fixture 一个进程：Club_Inbox_Reply 用一个 static 保证「应答只发一次」，同一个进程里重放第二条就再也拿不到状态码了。
 * 一个软件目录共用一个库，按 seq 从小到大跑：关注 -> 投稿 -> 编辑 -> 删除 -> 取关本来就是一条链，announce 要有关注者才扇得出去，update 要先有被转发过的帖子。 */

// expect 里认得的键。写错一个字的话那条断言会一声不吭地不生效，所以未知键当失败处理
$t_ap_keys = ['status', 'stored', 'follower_created', 'follower_removed', 'announce_created', 'announce_revoked',
    'content_updated', 'poll_updated', 'delivery_enqueued', 'relayed', 'actor_deleted'];

function t_ap_dir() {
    return TEST_ROOT.'/fixtures/activitypub';
}

// 按软件分组，组内按 seq 再按文件名排。seq 相同的场景之间没有先后关系，用文件名兜住排序的稳定性
function t_ap_list() {
    $all = [];
    foreach (glob(t_ap_dir().'/*', GLOB_ONLYDIR) as $dir) {
        if (basename($dir) === 'keys') continue;
        $fixtures = [];
        foreach (glob($dir.'/*.php') as $file) $fixtures[basename($file, '.php')] = require($file);
        uksort($fixtures, function ($a, $b) use ($fixtures) {
            $order = $fixtures[$a]['seq'] - $fixtures[$b]['seq'];
            return $order ?: strcmp($a, $b);
        });
        $all[basename($dir)] = $fixtures;
    }
    ksort($all);
    return $all;
}

function t_ap_body($fixture) {
    return $fixture['request']['body'];
}

// 报文里的活动。顶层数组（Foundkey 那种）拆一层，跟 Club_Inbox_Dispatch 的判断保持一致
function t_ap_activity($fixture) {
    $jsonld = json_decode(t_ap_body($fixture), 1);
    return count($jsonld) === 1 && isset($jsonld[0]) && is_array($jsonld[0]) ? $jsonld[0] : $jsonld;
}

// 路径决定这是群组 inbox 还是 shared inbox，跟 src/controller.php 的路由同形。null 就是 shared inbox
function t_ap_club($fixture) {
    return preg_match('#^/club/([^/]+)/inbox$#', $fixture['request']['path'], $m) ? $m[1] : null;
}

// 断言要查哪个群组的关注关系。同一条 Follow 走 shared inbox 时路径里没有群组名，目标在报文的 object 里 —— GoToSocial 就是这么发的
function t_ap_target($fixture, $activity) {
    if (($club = t_ap_club($fixture)) !== null) return $club;
    $object = isset($activity['object']) ? $activity['object'] : '';
    $name = Club_Object_Name($object);
    if ($name === false && is_array($object) && isset($object['object'])) $name = Club_Object_Name($object['object']);
    return $name === false ? '' : $name;
}

/* ---- 父进程 ---- */

function t_ap_run() {
    global $t_pass, $t_fail, $t_ap_keys;
    foreach (t_ap_list() as $software => $fixtures) {
        t_group('activitypub / '.$software);
        t_ap_seed($fixtures);
        foreach ($fixtures as $name => $fixture) {
            foreach (array_keys($fixture['expect']) as $key)
                if (!in_array($key, $t_ap_keys, true)) t_ok(false, $software.'/'.$name.': unknown expect key "'.$key.'"');
            list($output, $code) = t_ap_spawn($software, $name);
            // 子进程把计数打在最后一行，其余原样透出来，失败信息才不会被吞掉
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
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(TEST_ROOT.'/run.php').' replay '.escapeshellarg($software).' '.escapeshellarg($name);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return ['', -1];
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [rtrim($output, "\r\n"), proc_close($process)];
}

// 一个软件一份干净的库。群组和 actor 都预置好：Club_Get_Actor 查不到就会去拉远端，而测试不出网
function t_ap_seed($fixtures) {
    global $config;
    t_db_import();
    $public = file_get_contents(t_ap_dir().'/keys/actor-public.pem');
    $private = file_get_contents(t_ap_dir().'/keys/actor-private.pem');
    foreach (['test', $config['club']['system-name']] as $club)
        t_exec('insert into `clubs`(`name`,`public_key`,`private_key`,`timestamp`) values (:name, :public, :private, :now)',
            [':name' => $club, ':public' => $public, ':private' => $private, ':now' => time()]);
    $actors = [];
    foreach ($fixtures as $fixture) {
        $actor = Club_Object_Id(t_ap_activity($fixture)['actor'] ?? '');
        if ($actor !== '') $actors[$actor] = true;
    }
    foreach (array_keys($actors) as $actor) {
        $host = parse_url($actor, PHP_URL_HOST);
        t_exec('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`,`refresh`) values (:name, :actor, :inbox, :public, :shared, :now, :now)',
            [':name' => basename($actor).'@'.$host, ':actor' => $actor, ':inbox' => $actor.'/inbox', ':public' => $public, ':shared' => 'https://'.$host.'/inbox', ':now' => time()]);
    }
    // 另一家实例上的一个关注者，预置好不经过任何 fixture。透传转发会把来源实例整条 shared_inbox 排掉 ——
    // 那一包本来就是它发来的 —— 所以只有投稿者自己那一家关注的话，扇出目标是空的，转发入没入队根本看不出来
    t_exec('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`,`refresh`) values (:name, :actor, :inbox, :public, :shared, :now, :now)',
        [':name' => 'bob@other.example', ':actor' => 'https://other.example/users/bob', ':inbox' => 'https://other.example/users/bob/inbox',
            ':public' => $public, ':shared' => 'https://other.example/inbox', ':now' => time()]);
    t_exec('insert into `followers`(`cid`,`uid`,`timestamp`) select `c`.`cid`, `u`.`uid`, :now from `clubs` `c`, `users` `u` where `c`.`name` = \'test\' and `u`.`actor` = :actor',
        [':actor' => 'https://other.example/users/bob', ':now' => time()]);
}

/* ---- 子进程：一条 fixture ---- */

function t_ap_replay($software, $name) {
    $file = t_ap_dir().'/'.$software.'/'.$name.'.php';
    if (!is_file($file)) return t_ok(false, $software.'/'.$name.': fixture not found');
    $fixture = require($file);
    $body = t_ap_body($fixture);
    $club = t_ap_club($fixture);
    $activity = t_ap_activity($fixture);
    $actor = Club_Object_Id($activity['actor'] ?? '');
    $object = Club_Object_Id(isset($activity['object']) ? $activity['object'] : '');
    $label = $software.'/'.$name;

    $target = t_ap_target($fixture, $activity);
    $_SERVER = t_ap_server($fixture, $body, $actor);
    $before = t_ap_state($actor, $target, $object, $body);
    ob_start();
    Club_Inbox_Process($club, $body);
    $reply = json_decode(ob_get_clean(), 1);
    $after = t_ap_state($actor, $target, $object, $body);

    foreach ($fixture['expect'] as $key => $want) switch ($key) {
        // 200 不带 code，而 inbox 从不回 200，所以取不到就是压根没应答
        case 'status': t_is(isset($reply['code']) ? (int)$reply['code'] : 0, $want, $label.' status'); break;
        // 新落一行 activities，而且类型对得上。不比 id 也不比 object：去重键的拼法各实现不同，那是 Club_Tombstone_Process 自己的事
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
        // 透传转发的判据只有一条：入队的那份字节跟收到的一模一样。解码再编码会把 {} 变成 []，LD 签名当场作废
        case 'relayed': t_is($after['relayed'] > $before['relayed'], $want, $label.' original payload relayed verbatim'); break;
        case 'actor_deleted': t_is($before['user'] === 1 && $after['user'] === 0, $want, $label.' actor deleted'); break;
    }
}

// 请求环境。Content-Type / Content-Length 在 $_SERVER 里没有 HTTP_ 前缀，签名那边按同一套规则找回来
function t_ap_server($fixture, $body, $actor) {
    global $config;
    $request = $fixture['request'];
    $server = ['REQUEST_METHOD' => $request['method'], 'REQUEST_URI' => $request['path'],
        'HTTP_HOST' => $config['base'], 'CONTENT_LENGTH' => (string)strlen($body)];
    foreach (isset($request['headers']) ? $request['headers'] : [] as $key => $value) {
        $key = strtoupper(str_replace('-', '_', $key));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH']) ? $key : 'HTTP_'.$key] = $value;
    }
    $sign = isset($request['sign']) ? $request['sign'] : true;
    if ($sign === false) return $server;
    $keyId = is_array($sign) && isset($sign['keyId']) ? $sign['keyId'] : $actor.'#main-key';
    $date = gmdate('D, d M Y H:i:s T', is_array($sign) && isset($sign['date']) ? $sign['date'] : time());
    $digest = 'SHA-256='.base64_encode(hash('sha256', $body, true));
    $server['HTTP_DATE'] = $date; $server['HTTP_DIGEST'] = $digest;
    $signed = '(request-target): '.strtolower($request['method']).' '.$request['path']."\nhost: ".$config['base']."\ndate: ".$date."\ndigest: ".$digest;
    openssl_sign($signed, $signature, file_get_contents(t_ap_dir().'/keys/actor-private.pem'), OPENSSL_ALGO_SHA256);
    $server['HTTP_SIGNATURE'] = 'keyId="'.$keyId.'",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="'.base64_encode($signature).'"';
    return $server;
}

// 一次重放前后各取一次。都是计数和版本号，不碰自增 id 和时间戳
function t_ap_state($actor, $club, $object, $body) {
    return [
        'followers' => (int)t_one('select count(*) from `followers` `f` join `clubs` `c` on f.cid = c.cid join `users` `u` on f.uid = u.uid where c.name = :club and u.actor = :actor',
            [':club' => $club, ':actor' => $actor]),
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
