<?php require('class/curl.php');

function ActivityPub_GET($url, $club) {
    $date = gmdate('D, d M Y H:i:s T');
    return ActivityPub_CURL($url, $date, [
        'Signature' => ActivityPub_Signature($url, $club, $date)
    ]);
}

function ActivityPub_POST($url, $club, $jsonld) {
    $date = gmdate('D, d M Y H:i:s T');
	$digest = base64_encode(hash('sha256', $jsonld, 1));
    return ActivityPub_CURL($url, $date, [
        'Signature' => ActivityPub_Signature($url, $club, $date, $digest),
        'Digest' => 'SHA-256='.$digest
    ], $jsonld);
}

function ActivityPub_CURL($url, $date, $head, $data = null) {
    global $ver, $base, $curl, $config; static $last_head = [];
    if (!isset($curl)) $curl = new Curl();
    $curl->setTimeout(10);
    $curl->setConnectTimeout(3);
    $curl->setMaximumRedirects(3);
    $curl->setUserAgent('wxwClub '.$ver.'; '.$base);
    $curl->setHeader('Accept', 'application/activity+json');
    $curl->setHeader('Content-Type', 'application/activity+json');
    $curl->setHeader('Date', $date);
    // Curl 实例是复用的，先清掉上次请求独有的头，避免 POST 的 Digest 残留到之后的 GET
    foreach (array_diff(array_keys($last_head), array_keys($head)) as $k) $curl->unsetHeader($k);
    foreach ($head as $k => $v) $curl->setHeader($k, $v);
    $last_head = $head;
    if (isset($data)) $curl->post($url, $data); else $curl->get($url);
    if ($config['nodeDebugging'] == 1) {
        $status = $curl->responseHeaders['Status-Line'] ?? '';
        $info = substr($status, -1) == ' ' ? '' : ' ';
        $info = str_replace(['https://', '/', ' ', '\\'], ['', 'Ⳇ', '_', 'Ⳇ'], strtolower($status).$info.$url);
        $file_name = date('Y-m-d_H:i:s_').(isset($data)?'post':'get').'_'.$info;
        file_put_contents(APP_ROOT.'/logs/curl/'.$file_name.'.json', Club_Json_Encode([
            'header' => $curl->responseHeaders, 'result' => $curl->response, 'error' => $curl->error
        ]));
    } return $curl->error ? false : ($curl->response ?: true);
}

function ActivityPub_Signature($url, $club, $date, $digest = null) {
    global $db, $base; $host = ($url_parts = parse_url($url))['host']; $path = '/';
	
	if (!empty($url_parts['path'])) $path = $url_parts['path'];
	if (!empty($url_parts['query'])) $path .= '?' . $url_parts['query'];
	
	$signed_string = "(request-target): ".(empty($digest)?'get':'post')." $path\nhost: $host\ndate: $date".(empty($digest)?'':"\ndigest: SHA-256=$digest");
	$pdo = $db->prepare('select `private_key` from `clubs` where `name` = :name');
    $pdo->execute([':name' => $club]);
    if ($pdo = $pdo->fetch(PDO::FETCH_ASSOC)) {
        openssl_sign($signed_string, $signature, $pdo['private_key'], OPENSSL_ALGO_SHA256);
        return 'keyId="'.$base.'/club/'.$club.'#main-key'.'",algorithm="rsa-sha256",headers="(request-target) host date'.(empty($digest)?'':' digest').'",signature="'.base64_encode($signature).'"';
    } return false;
}

function ActivityPub_Verification($input = null, $pull = true) {
    global $db, $verify_signed; if (empty($_SERVER['HTTP_SIGNATURE'])) return ActivityPub_Verify_Fail('no signature header');
    $signature = [];
    preg_match_all('/[,\s]*(.*?)="(.*?)"/', $_SERVER['HTTP_SIGNATURE'], $matches);
    foreach ($matches[1] as $k => $v) $signature[$v] = $matches[2][$k];
    if (empty($signature['keyId']) || empty($signature['signature']) || empty($signature['headers']))
        return ActivityPub_Verify_Fail('malformed signature header');

    $post = strtolower($_SERVER['REQUEST_METHOD']) == 'post';
    // headers= 的顺序就是签名串的行顺序，(request-target) 不一定排在第一个
    $headers = explode(' ', strtolower($signature['headers']));
    if (!in_array('(request-target)', $headers)) return ActivityPub_Verify_Fail('(request-target) not signed');
    // 必须签名 date 或 (created) 并校验时效，否则签名可以被无限重放
    if (in_array('date', $headers)) {
        if (!ActivityPub_Date_Verify($_SERVER['HTTP_DATE'] ?? ''))
            return ActivityPub_Verify_Fail('date out of range: '.($_SERVER['HTTP_DATE'] ?? '-').' vs '.gmdate('D, d M Y H:i:s T'));
    } elseif (in_array('(created)', $headers)) {
        if (!ActivityPub_Date_Verify($signature['created'] ?? ''))
            return ActivityPub_Verify_Fail('(created) out of range: '.($signature['created'] ?? '-').' vs '.time());
    } else return ActivityPub_Verify_Fail('neither date nor (created) signed');
    if (in_array('(expires)', $headers) && ($signature['expires'] ?? PHP_INT_MAX) < time())
        return ActivityPub_Verify_Fail('signature expired at '.$signature['expires']);
    // POST 必须签名 digest 头，否则请求体可以被任意替换
    if ($post && !in_array('digest', $headers)) return ActivityPub_Verify_Fail('digest not signed');
    if ($post && empty($_SERVER['HTTP_DIGEST'])) return ActivityPub_Verify_Fail('digest header missing');

    $lines = [];
    foreach ($headers as $header) {
        switch ($header) {
            case '(request-target)':
                $lines[] = $header.': '.strtolower($_SERVER['REQUEST_METHOD']).' '.$_SERVER['REQUEST_URI']; break;
            case '(created)': case '(expires)':
                $lines[] = $header.': '.($signature[trim($header, '()')] ?? ''); break;
            default:
                // Content-Type / Content-Length 在 $_SERVER 里没有 HTTP_ 前缀
                $key = strtoupper(str_replace('-', '_', $header));
                if (!in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) $key = 'HTTP_'.$key;
                $lines[] = $header.': '.($_SERVER[$key] ?? '');
        }
    } $verify_signed = implode("\n", $lines);

    $actor = str_replace(['#main-key', '/main-key'], '', $signature['keyId']);
    $pdo = $db->prepare('select `public_key` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    if ($public_key = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
        $result = openssl_verify($verify_signed, base64_decode($signature['signature']), $public_key,
            str_replace('hs2019', 'rsa-sha256', $signature['algorithm'] ?? 'rsa-sha256'));
        if ($result === 1) return $post ? ActivityPub_Digest_Verify($input) : true;
        // 0 是签名对不上，-1 / false 是 openssl 本身出错（多半是公钥坏了）
        ActivityPub_Verify_Fail($result === 0 ? 'signature mismatch' : 'openssl error: '.openssl_error_string());
    } else ActivityPub_Verify_Fail('unknown actor: '.$actor);

    // 校验不过可能是本站还不认识这个 actor，也可能是对方轮换了密钥，拉取一次后重试
    if ($pull && Club_Sync_Actor($actor)) return ActivityPub_Verification($input, false);
    return false;
}

function ActivityPub_Verify_Fail($reason) {
    global $verify_reason; $verify_reason = $reason; return false;
}

// 群组 inbox 和 shared inbox 共用，避免两处日志代码走偏
function Club_Inbox_Log($file_name, $input, $verify) {
    global $config, $verify_reason, $verify_signed;
    file_put_contents(APP_ROOT.'/logs/inbox/'.$file_name.'_input.json', $input);
    if ($config['nodeDebugging'] == 1)
        file_put_contents(APP_ROOT.'/logs/inbox/'.$file_name.'_server.json', Club_Json_Encode($_SERVER));
    if (!$verify) file_put_contents(APP_ROOT.'/logs/inbox/'.$file_name.'_verify_failed.txt',
        'reason: '.($verify_reason ?? '-')."\n\nsignature: ".($_SERVER['HTTP_SIGNATURE'] ?? '-').
        "\n\ndigest: ".($_SERVER['HTTP_DIGEST'] ?? '-')."\n\nsigned string:\n".($verify_signed ?? '-'));
}

function ActivityPub_Date_Verify($date, $skew = 300) {
    if (empty($date)) return false;
    // Date 头是 HTTP 日期，(created) 是 unix 时间戳
    $time = ctype_digit((string)$date) ? (int)$date : strtotime($date);
    return $time && abs(time() - $time) <= $skew;
}

function ActivityPub_Digest_Verify($input) {
    if (!preg_match('/([A-Za-z0-9-]+)\s*=\s*([A-Za-z0-9+\/=]+)/', $_SERVER['HTTP_DIGEST'], $matches))
        return ActivityPub_Verify_Fail('malformed digest header');
    $algo = strtolower(str_replace('-', '', $matches[1]));
    if (!in_array($algo, ['sha256', 'sha512'])) return ActivityPub_Verify_Fail('unsupported digest algorithm: '.$matches[1]);
    if (!hash_equals(hash($algo, (string)$input, 1), base64_decode($matches[2])))
        return ActivityPub_Verify_Fail('digest mismatch, body length '.strlen((string)$input));
    return true;
}

function Club_Exist($club) {
    global $db, $config;
    if (strlen($club) <= 30 && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]+$/u', $club)) {
        $pdo = $db->prepare('select `name` from `clubs` where `name` = :name'); $pdo->execute([':name' => $club]);
        return ($pdo = $pdo->fetch(PDO::FETCH_COLUMN, 0)) ? $pdo : ($config['openRegistrations'] ? Club_Create($club) : false);
    } return false;
}

function Club_Create($club) {
    global $db, $config;
    if (!in_array(strtolower($club), $config['nodeSuspendedName'])) {
        $key = openssl_pkey_new([
    		'digest_alg' => 'sha512',
    		'private_key_bits' => 2048,
    		'private_key_type' => OPENSSL_KEYTYPE_RSA
    	]); if (!$key || !openssl_pkey_export($key, $priv_key)) return false;
        $detail = openssl_pkey_get_details($key);
        $pdo = $db->prepare('insert into `clubs`(`name`,`public_key`,`private_key`,`timestamp`) values(:name, :public, :private, :timestamp)');
        try { $pdo->execute([':name' => $club, ':public' => $detail['key'], ':private' => $priv_key, ':timestamp' => time()]); }
        catch (PDOException $e) { /* 并发建群撞唯一键，下面重查一次即可 */ }
        $pdo = $db->prepare('select `name` from `clubs` where `name` = :name');
        $pdo->execute([':name' => $club]);
        return $pdo->fetch(PDO::FETCH_COLUMN, 0);
    } return false;
}

function Club_Get_Actor($club, $actor) {
    global $db; $pdo = $db->prepare('select `uid`,`name`,`inbox`,`shared_inbox` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    return $pdo->fetch(PDO::FETCH_ASSOC) ?: Club_Fetch_Actor($club, $actor);
}

// 拉取远端 actor 并写入本地缓存，已存在则更新（对方可能轮换了密钥或迁移了 inbox）
function Club_Fetch_Actor($club, $actor) {
    global $db; $jsonld = json_decode(ActivityPub_GET($actor, $club), 1);
    if (empty($jsonld['id']) || $jsonld['id'] != $actor || empty($jsonld['inbox'])) return false;
    $data = [
        ':name' => ($jsonld['preferredUsername'] ?? '').'@'.parse_url($jsonld['id'], PHP_URL_HOST),
        ':inbox' => $jsonld['inbox'], ':public_key' => $jsonld['publicKey']['publicKeyPem'] ?? '',
        ':shared_inbox' => $jsonld['endpoints']['sharedInbox'] ?? $jsonld['inbox'],
        ':actor' => $jsonld['id'], ':timestamp' => time()
    ];
    $pdo = $db->prepare('select `uid` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    if ($pdo->fetch(PDO::FETCH_COLUMN, 0))
        $pdo = $db->prepare('update `users` set `name` = :name, `inbox` = :inbox, `public_key` = :public_key,'.
            ' `shared_inbox` = :shared_inbox, `refresh` = :timestamp where `actor` = :actor');
    else
        $pdo = $db->prepare('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`,`refresh`)'.
            ' values (:name, :actor, :inbox, :public_key, :shared_inbox, :timestamp, :timestamp)');
    try { $pdo->execute($data); }
    catch (PDOException $e) { /* 并发写入或用户名撞唯一键，下面重查一次 */ }
    $pdo = $db->prepare('select `uid`,`name`,`inbox`,`shared_inbox` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    return $pdo->fetch(PDO::FETCH_ASSOC);
}

// 供验签失败时刷新公钥用，带冷却时间，防止伪造签名把本站当外连放大器
function Club_Sync_Actor($actor, $cooldown = 3600) {
    global $db; $pdo = $db->prepare('select `refresh` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    $refresh = $pdo->fetch(PDO::FETCH_COLUMN, 0);
    if ($refresh !== false && $refresh > time() - $cooldown) return false;
    return ($club = Club_Any_Name()) ? Club_Fetch_Actor($club, $actor) : false;
}

// 对外签名时随便取一个已有群组，不必为内部用途单独建一个公开可见的群组
function Club_Any_Name() {
    global $db; $pdo = $db->query('select `name` from `clubs` limit 1');
    return $pdo->fetch(PDO::FETCH_COLUMN, 0);
}

function Club_Task_Create($type, $club, $jsonld) {
    global $db;
    $pdo = $db->prepare('insert into `tasks`(`cid`,`type`,`jsonld`,`timestamp`) select `cid`, :type as `type`, :jsonld as `jsonld`, :timestamp as `timestamp` from `clubs` where `name` = :club');
    $pdo->execute([':type' => $type, ':club' => $club, ':jsonld' => $jsonld, ':timestamp' => time()]);
    // 群组不存在时 insert-select 不会写入任何行，此时 last_insert_id() 是上一条的残值
    return $pdo->rowCount() ? $db->lastInsertId() : false;
}

function Club_Queue_Insert($task, $target) {
    global $db;
    $pdo = $db->prepare('insert into `queues`(`tid`,`target`,`timestamp`)'.
        ' select :tid, :target, :timestamp from dual where :check not in (select `target` from `blacklist`)');
    $pdo->execute([':tid' => $task, ':target' => $target, ':check' => $target, ':timestamp' => time()]);
    return Club_Queue_Count($task, $pdo->rowCount());
}

// 一次性把群组所有关注者的 shared_inbox 入队，避免按关注者数量逐条往返
function Club_Queue_Insert_Followers($task, $club, $inbox = false) {
    global $db;
    $params = [':tid' => $task, ':club' => $club, ':timestamp' => time()];
    if ($inbox !== false) $params[':inbox'] = $inbox;
    $pdo = $db->prepare('insert into `queues`(`tid`,`target`,`timestamp`) select :tid, `t`.`target`, :timestamp from ('.
        ' select distinct u.shared_inbox as `target` from `followers` `f`'.
        ' join `clubs` `c` on f.cid = c.cid join `users` `u` on f.uid = u.uid where c.name = :club'.
        ($inbox === false ? '' : ' union select :inbox as `target`').
        ') `t` where `t`.`target` not in (select `target` from `blacklist`)');
    $pdo->execute($params);
    return Club_Queue_Count($task, $pdo->rowCount());
}

function Club_Queue_Count($task, $count) {
    global $db; if (!$count) return true;
    $pdo = $db->prepare('update `tasks` set `queues` = `queues` + :count where `tid` = :tid');
    return $pdo->execute([':tid' => $task, ':count' => $count]);
}

function Club_Push_Activity($club, $activity, $inbox = false, $direct = false) {
    global $db, $config;
    $type = $activity['type'];
    $activity = Club_Json_Encode($activity);
    if ($config['nodeDebugging']) {
        $file_name = date('Y-m-d_H:i:s_').$club.'_'.$type;
        file_put_contents(APP_ROOT.'/logs/outbox/'.$file_name.'_output.json', $activity);
        if ($config['nodeDebugging'] == 1) file_put_contents(APP_ROOT.'/logs/outbox/'.$file_name.'_server.json', Club_Json_Encode($_SERVER));
    }
    $commit = false;
    try {
        $db->beginTransaction();
        if ($task = Club_Task_Create('push', $club, $activity)) {
            if ($direct) Club_Queue_Insert($task, $inbox);
            else Club_Queue_Insert_Followers($task, $club, $inbox);
            $commit = $db->commit();
        }
    } catch (PDOException $e) { error_log('Push failed: '.$e->getMessage()); }
    if (!$commit) {
        if ($config['nodeDebugging']) file_put_contents(APP_ROOT.'/logs/outbox/'.$file_name.'_commit_failed', '');
        if ($db->inTransaction()) $db->rollback();
    }
}

function Club_Announce_Process($jsonld) {
    global $db, $base, $config, $public_streams;
    if (empty($jsonld['object']['id'])) return;
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $jsonld['object']['id']]);
    if (!$pdo->fetch(PDO::FETCH_ASSOC)) {
        $prefix = $base.'/club/';
        foreach ($to = array_merge(to_array($jsonld['to'] ?? []), to_array($jsonld['cc'] ?? [])) as $cc)
            if ($prefix == substr($cc, 0, strlen($prefix)))
                if ($club = Club_Exist(explode('/', substr($cc, strlen($prefix)))[0])) $clubs[$club] = 1;
        if (!empty($clubs) && ($clubs = array_keys($clubs)) && in_array($public_streams, $to)) {
            if ($actor = Club_Get_Actor($clubs[0], $jsonld['actor'])) {
                // 同一条内容可能同时投递到 shared inbox 和群组 inbox，靠唯一键去重防止重复转发
                $pdo = $db->prepare('insert ignore into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
                $pdo->execute([':uid' => $actor['uid'], ':type' => 'Create', ':clubs' => Club_Json_Encode($clubs), ':object' => $jsonld['object']['id'], ':timestamp' => ($time = time())]);
                if ($pdo->rowCount() && ($activity_id = $db->lastInsertId())) {
                    $content = strip_tags($jsonld['object']['content'] ?? '');
                    $published = strtotime($jsonld['object']['published'] ?? '') ?: $time;
                    foreach ($clubs as $club) {
                        // Posting limits for large clubs
                        if (in_array($club, $config['nodeLimitedName'] ?? [])) {
                            $reject = false;
                            // Reject duplicate content within 24 hours
                            $pdo = $db->prepare('select `id` from `announces` where `uid` = :uid and `timestamp` >= :timestamp and `content` = :content limit 1');
                            $pdo->execute([':uid' => $actor['uid'], ':timestamp' => time() - 60 * 60 * 24, ':content' => $content]);
                            if ($pdo->fetch(PDO::FETCH_COLUMN, 0)) $reject = true;
                            // Limit regular posts to 10 within 24 hours
                            $pdo = $db->prepare('select count(a.id) from `announces` `a` join `clubs` `c` on a.cid = c.cid'.
                                ' where a.uid = :uid and a.timestamp >= :timestamp and c.name = :club');
                            $pdo->execute([':uid' => $actor['uid'], ':timestamp' => time() - 60 * 60 * 24, ':club' => $club]);
                            if ($pdo->fetch(PDO::FETCH_COLUMN, 0) >= 10) $reject = true;
                            // Skip content that exceeds the limits
                            if ($reject) {
                                if ($config['nodeDebugging']) {
                                    $file_name = date('Y-m-d_H:i:s_').$club.'_spam';
                                    file_put_contents(APP_ROOT.'/logs/filter/'.$file_name.'.json', Club_Json_Encode($jsonld));
                                } continue;
                            }
                        } $club_url = $base.'/club/'.$club;
                        Club_Push_Activity($club, [
                            '@context' => 'https://www.w3.org/ns/activitystreams',
                            'id' => $club_url.'/activity#'.$activity_id.'/announce',
                            'type' => 'Announce',
                            'actor' => $club_url,
                            'published' => gmdate('Y-m-d\TH:i:s\Z', $time),
                            'to' => [$club_url.'/followers'],
                            'cc' => [$jsonld['actor'], $public_streams],
                            'object' => $jsonld['object']['id']
                        ], $actor['shared_inbox']);
                        $pdo = $db->prepare('insert ignore into `announces`(`cid`,`uid`,`activity`,`summary`,`content`,`timestamp`)'.
                            ' select `cid`, :uid as `uid`, :activity as `activity`, :summary as `summary`, :content as `content`, :timestamp as `timestamp` from `clubs` where `name` = :club');
                        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':activity' => $activity_id,
                            ':summary' => $jsonld['object']['summary'] ?? null, ':content' => $content, ':timestamp' => $published]);
                    }
                }
            } else Club_Json_Output(['message' => 'Actor not found'], 0, 400);
        }
    }
}

function Club_Follow_Process($jsonld) {
    global $db, $base;
    if (!($club = Club_Object_Name($jsonld['object'] ?? ''))) return;
    if ($actor = Club_Get_Actor($club, $jsonld['actor'])) {
        // 对方重发 Follow 是常态，撞唯一键时保留原记录即可
        $pdo = $db->prepare('insert ignore into `followers`(`cid`,`uid`,`timestamp`) select `cid`, :uid as `uid`, :timestamp as `timestamp` from `clubs` where `name` = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':timestamp' => time()]);
        $pdo = $db->prepare('select f.id from `followers` as f left join `clubs` as `c` on f.cid = c.cid where f.uid = :uid and c.name = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid']]);
        $follow_id = $pdo->fetch(PDO::FETCH_COLUMN, 0);
        $club_url = $base.'/club/'.$club;
        if ($follow_id) {
            Club_Push_Activity($club, [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $club_url.'#accepts/follows/'.$follow_id,
                'type' => 'Accept',
                'actor' => $club_url,
                'object' => [
                    'id' => $jsonld['id'],
                    'type' => 'Follow',
                    'actor' => $jsonld['actor'],
                    'object' => $club_url
                ]
            ], $actor['inbox'], true);
        }
    } else Club_Json_Output(['message' => 'Actor not found'], 0, 400);
}

function Club_Tombstone_Process($jsonld) {
    global $db, $base, $public_streams;
    if (empty($jsonld['id']) || empty($jsonld['object']['id'])) return;
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $jsonld['id']]);
    if (!$pdo->fetch(PDO::FETCH_ASSOC)) {
        $pdo = $db->prepare('select `id`,`uid`,`clubs`,`object`,`timestamp` from `activities` where `object` = :object');
        $pdo->execute([':object' => $jsonld['object']['id']]);
        if ($activity = $pdo->fetch(PDO::FETCH_ASSOC)) {
            // 撤销记录同样靠唯一键防止重复投递触发两次 Undo
            $pdo = $db->prepare('insert ignore into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
            $pdo->execute([':uid' => $activity['uid'], ':type' => 'Delete', ':clubs' => $activity['clubs'], ':object' => $jsonld['id'], ':timestamp' => time()]);
            if (!$pdo->rowCount()) return;
            foreach (json_decode($activity['clubs'], 1) as $club) {
                $club_url = $base.'/club/'.$club;
                Club_Push_Activity($club, [
                    '@context' => 'https://www.w3.org/ns/activitystreams',
                    'id' => $club_url.'/activity#'.$activity['id'].'/undo',
                    'type' => 'Undo',
                    'actor' => $club_url,
                    'to' => $public_streams,
                        'object' => [
                        'id' => $club_url.'/activity#'.$activity['id'].'/announce',
                        'type' => 'Announce',
                        'actor' => $club_url,
                        'published' => gmdate('Y-m-d\TH:i:s\Z', $activity['timestamp']),
                        'to' => [$club_url.'/followers'],
                        'cc' => [
                            $jsonld['actor'],
                            $public_streams
                        ],
                        'object' => $activity['object']
                    ]
                ]);
            }
            $pdo = $db->prepare('delete from `announces` where `activity` = :activity');
            $pdo->execute([':activity' => $activity['id']]);
        }
    }
}

function Club_Undo_Process($jsonld) {
    global $db; if (!is_array($jsonld['object'] ?? null)) return;
    switch ($jsonld['object']['type'] ?? '') {
        case 'Follow':
            if (!($club = Club_Object_Name($jsonld['object']['object'] ?? ''))) break;
            $pdo = $db->prepare('delete from `followers` where `cid` in (select cid from `clubs` where `name` = :club) and `uid` in (select uid from `users` where `actor` = :actor)');
            $pdo->execute([':club' => $club, ':actor' => $jsonld['actor']]); break;
        default: break;
    }
}

function Club_Get_OrderedCollection($id, $arr = []) {
    $arr = array_merge([
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $id,
        'type' => 'OrderedCollection',
        'totalItems' => 0
    ], $arr);
    Club_Json_Output($arr, 2);
}

// 从 Follow / Undo 的 object 里取出群组名，object 可能是字符串也可能是内嵌对象
function Club_Object_Name($object) {
    if (is_array($object)) $object = $object['id'] ?? '';
    if (count($parts = explode('/club/', (string)$object)) < 2) return false;
    return explode('/', $parts[1])[0];
}

function Club_NameTag_Render($club, $str, $tag) {
    global $config;
    $str = str_replace(array_keys($tag), array_values($tag), $str);
    return str_replace([':club_name:', ':local_domain:'], [$club, $config['base']], $str);
}

function Club_Json_Encode($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function Club_Json_Output($data, $format = 0, $status = 200) {
    switch ($format) {
        case 1: $format = 'jrd+json'; break;
        case 2: $format = 'activity+json'; break;
        default: $format = 'json'; break;
    } header('Content-type: application/'.$format.'; charset=utf-8');
    
    if ($status != 200) {
        http_response_code($status);
        $data = array_merge(['code' => $status], $data);
    } echo Club_Json_Encode($data);
}

function to_array($data) {
    return is_array($data) ? $data : [$data];
}
