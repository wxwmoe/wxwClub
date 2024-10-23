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
    global $ver, $base, $curl, $config;
    if (!isset($curl)) $curl = new Curl();
    $curl->setTimeout(10);
    $curl->setConnectTimeout(3);
    $curl->setMaximumRedirects(3);
    $curl->setUserAgent('wxwClub '.$ver.'; '.$base);
    $curl->setHeader('Accept', 'application/activity+json');
    $curl->setHeader('Content-Type', 'application/activity+json');
    $curl->setHeader('Date', $date);
    foreach ($head as $k => $v) $curl->setHeader($k, $v);
    if (isset($data)) $curl->post($url, $data); else $curl->get($url);
    if ($config['nodeDebugging'] == 1) {
        $info = substr($curl->responseHeaders['Status-Line'], -1) == ' ' ? '' : ' ';
        $info = str_replace(['https://', '/', ' ', '\\'], ['', 'Ⳇ', '_', 'Ⳇ'], strtolower($curl->responseHeaders['Status-Line']).$info.$url);
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
    global $db; if (isset($_SERVER['HTTP_SIGNATURE'])) {
        preg_match_all('/[,\s]*(.*?)="(.*?)"/', $_SERVER['HTTP_SIGNATURE'], $matches);
        foreach ($matches[1] as $k => $v) $signature[$v] = $matches[2][$k];
        if (($headers = explode(' ', $signature['headers']))[0] == '(request-target)') {
            $actor = str_replace(['#main-key', '/main-key'], '', $signature['keyId']);
            $pdo = $db->prepare('select `public_key` from `users` where `actor` = :actor');
            $pdo->execute([':actor' => $actor]);
            if ($public_key = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
                $signed_string = '(request-target): '.strtolower($_SERVER['REQUEST_METHOD']).' '.$_SERVER['REQUEST_URI'];
                foreach (array_slice($headers, 1) as $header) $signed_string .= "\n".$header.': '.$_SERVER['HTTP_'.strtoupper(str_replace('-','_',$header))];
                if (openssl_verify($signed_string, base64_decode($signature['signature']), $public_key,  str_replace('hs2019', 'rsa-sha256', $signature['algorithm']))) {
                    if (isset($_SERVER['HTTP_DIGEST'])) {
                        preg_match('/^(.*?)=(.*?)$/', $_SERVER['HTTP_DIGEST'], $matches);
                        return (hash(str_replace('-','',$matches[1]), $input, 1) == base64_decode($matches[2]));
                    } return true;
                }
            } elseif ($pull) {
                $pdo = $db->query('select `name` from `clubs` limit 1');
                $club = $pdo->fetch(PDO::FETCH_COLUMN, 0);
                if (Club_Get_Actor($club, $actor))
                    return ActivityPub_Verification($input, false);
            }
        }
    } return false;
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
    	]); openssl_pkey_export($key, $priv_key);
        $detail = openssl_pkey_get_details($key);
        $pdo = $db->prepare('insert into `clubs`(`name`,`public_key`,`private_key`,`timestamp`) values(:name, :public, :private, :timestamp)');
        return $pdo->execute([':name' => $club, ':public' => $detail['key'], ':private' => $priv_key, ':timestamp' => time()]) ? $club : false;
    } return false;
}

function Club_Get_Actor($club, $actor) {
    global $db; $pdo = $db->prepare('select `uid`,`name`,`inbox` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    if ($pdo = $pdo->fetch(PDO::FETCH_ASSOC)) {
        $uid = $pdo['uid'];
        $name = $pdo['name'];
        $inbox = $pdo['inbox'];
    } else {
        $jsonld = json_decode(ActivityPub_GET($actor, $club), 1);
        if ($jsonld['id'] == $actor) {
            $inbox = $jsonld['inbox'];
            $shared_inbox = $jsonld['endpoints']['sharedInbox'] ?: $jsonld['inbox'];
            $name = $jsonld['preferredUsername'].'@'.parse_url($jsonld['id'], PHP_URL_HOST);
            $pdo = $db->prepare('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`) values (:name, :actor, :inbox, :public_key, :shared_inbox, :timestamp)');
            $pdo->execute([
                ':name' => $name, ':actor' => $jsonld['id'], ':inbox' => $jsonld['inbox'], ':timestamp' => time(),
                ':public_key' => $jsonld['publicKey']['publicKeyPem'], ':shared_inbox' => $shared_inbox
            ]);
            $pdo = $db->query('select last_insert_id()');
            $uid = $pdo->fetch(PDO::FETCH_COLUMN, 0);
        } else return false;
    } return ['uid' => $uid, 'name' => $name, 'inbox' => $inbox];
}

function Club_Task_Create($type, $club, $jsonld) {
    global $db;
    $pdo = $db->prepare('insert into `tasks`(`cid`,`type`,`jsonld`,`timestamp`) select `cid`, :type as `type`, :jsonld as `jsonld`, :timestamp as `timestamp` from `clubs` where `name` = :club');
    $pdo->execute([':type' => $type, ':club' => $club, ':jsonld' => $jsonld, ':timestamp' => time()]);
    $pdo = $db->query('select last_insert_id()');
    return $pdo->fetch(PDO::FETCH_COLUMN, 0);
}

function Club_Queue_Insert($task, $target) {
    global $db;
    $pdo = $db->prepare('select count(*) from `blacklist` where `target` = :target');
    $pdo->execute([':target' => $target]);
    if (empty($pdo->fetch(PDO::FETCH_COLUMN, 0))) {
        $pdo = $db->prepare('insert into `queues`(`tid`,`target`,`timestamp`) values (:tid, :target, :timestamp)');
        if ($pdo->execute([':tid' => $task, ':target' => $target, ':timestamp' => time()])) {
            $pdo = $db->prepare('update `tasks` set `queues` = `queues` + 1 where `tid` = :tid');
            return $pdo->execute([':tid' => $task]);
        }
    } return false;
}

function Club_Push_Activity($club, $activity, $inbox = false) {
    global $db, $config;
    $type = $activity['type'];
    $activity = Club_Json_Encode($activity);
    if ($config['nodeDebugging']) {
        $file_name = date('Y-m-d_H:i:s_').$club.'_'.$type;
        file_put_contents(APP_ROOT.'/logs/outbox/'.$file_name.'_output.json', $activity);
        if ($config['nodeDebugging'] == 1) file_put_contents(APP_ROOT.'/logs/outbox/'.$file_name.'_server.json', Club_Json_Encode($_SERVER));
    }
    $commit = false;
    $pdo = $db->beginTransaction();
    if ($task = Club_Task_Create('push', $club, $activity)) {
        if ($inbox) Club_Queue_Insert($task, $inbox);
        else {
            $pdo = $db->prepare('select distinct u.shared_inbox from `followers` `f` join `clubs` `c` on f.cid = c.cid join `users` `u` on f.uid = u.uid where c.name = :club');
            $pdo->execute([':club' => $club]);
            foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $inbox) Club_Queue_Insert($task, $inbox);
        } $commit = $db->commit();
    } if (!$commit) {
        if ($config['nodeDebugging']) file_put_contents(APP_ROOT.'/logs/outbox/'.$file_name.'_commit_failed');
        $pdo = $db->rollback();
    }
}

function Club_Announce_Process($jsonld) {
    global $config, $db, $base, $public_streams;
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $jsonld['object']['id']]);
    if (!$pdo->fetch(PDO::FETCH_ASSOC)) {
        foreach ($to = array_merge(to_array($jsonld['to']), to_array($jsonld['cc'])) as $cc)
            if (($club_url = $base.'/club/') == substr($cc, 0, strlen($club_url)))
                if ($club = Club_Exist(explode('/', substr($cc, strlen($club_url)))[0])) $clubs[$club] = 1;
        if (!empty($clubs) && ($clubs = array_keys($clubs)) && in_array($public_streams, $to)) {
            if ($actor = Club_Get_Actor($clubs[0], $jsonld['actor'])) {
                if (isBlocked($actor['name'], $clubs)) {
                    if ($config['nodeDebugging'] == 1) {
                        file_put_contents(APP_ROOT.'/logs/blocklist/'.date('Y-m-d_H:i:s').'_blocked.json', Club_Json_Encode($jsonld));
                    }
                    return;
                }
                $pdo = $db->prepare('insert into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
                $pdo->execute([':uid' => $actor['uid'], ':type' => 'Create', ':clubs' => Club_Json_Encode($clubs), 'object' => $jsonld['object']['id'], 'timestamp' => ($time = time())]);
                $pdo = $db->query('select last_insert_id()');
                if ($activity_id = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
                    foreach ($clubs as $club) {
                        $club_url = $base.'/club/'.$club;
                        Club_Push_Activity($club, [
                            '@context' => 'https://www.w3.org/ns/activitystreams',
                            'id' => $club_url.'/activity#'.$activity_id.'/announce',
                            'type' => 'Announce',
                            'actor' => $club_url,
                            'published' => gmdate('Y-m-d\TH:i:s\Z', $time),
                            'to' => [$club_url.'/followers'],
                            'cc' => [$jsonld['actor'], $public_streams],
                            'object' => $jsonld['object']['id']
                        ]);
                        $pdo = $db->prepare('insert into `announces`(`cid`,`uid`,`activity`,`summary`,`content`,`timestamp`)'.
                            ' select `cid`, :uid as `uid`, :activity as `activity`, :summary as `summary`, :content as `content`, :timestamp as `timestamp` from `clubs` where `name` = :club');
                        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':activity' => $activity_id,
                            ':summary' => $jsonld['object']['summary'], ':content' => strip_tags($jsonld['object']['content']), ':timestamp' => strtotime($jsonld['object']['published'])]);
                    }
                }
            } else Club_Json_Output(['message' => 'Actor not found'], 0, 400);
        }
    }
}

function Club_Follow_Process($jsonld) {
    global $db, $base;
    $club = explode('/club/', $jsonld['object'])[1];
    if ($actor = Club_Get_Actor($club, $jsonld['actor'])) {
        $pdo = $db->prepare('insert into `followers`(`cid`,`uid`,`timestamp`) select `cid`, :uid as `uid`, :timestamp as `timestamp` from `clubs` where `name` = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':timestamp' => time()]);
        $pdo = $db->prepare('select f.id from `followers` as f left join `clubs` as `c` on f.cid = c.cid where f.uid = :uid and c.name = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid']]);
        if ($follow_id = $pdo->fetch(PDO::FETCH_COLUMN, 0) && $club_url = $base.'/club/'.$club) {
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
            ], $actor['inbox']);
        }
    } else Club_Json_Output(['message' => 'Actor not found'], 0, 400);
}

function Club_Tombstone_Process($jsonld) {
    global $db, $base, $public_streams;
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $jsonld['id']]);
    if (!$pdo->fetch(PDO::FETCH_ASSOC)) {
        $pdo = $db->prepare('select `id`,`uid`,`clubs`,`object`,`timestamp` from `activities` where `object` = :object');
        $pdo->execute([':object' => $jsonld['object']['id']]);
        if ($activity = $pdo->fetch(PDO::FETCH_ASSOC)) {
            $pdo = $db->prepare('insert into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
            $pdo->execute([':uid' => $activity['uid'], ':type' => 'Delete', ':clubs' => $activity['clubs'], 'object' => $jsonld['id'], 'timestamp' => time()]);
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
    global $db; switch ($jsonld['object']['type']) {
        case 'Follow':
            $club = explode('/club/', $jsonld['object']['object'])[1];
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

function block($type, $target, $club = null) {
    global $db;
    $table = ($type == 'user') ? 'users_blocks' : 'instances_blocks';
    $club_id = null;
    if ($club) {
        $pdo = $db->prepare('SELECT cid FROM clubs WHERE name = :name');
        $pdo->execute([':name' => $club]);
        $club_id = $pdo->fetchColumn();
        if (!$club_id) {
            echo "Error: Club $club not found.\n";
            return;
        }
    }
    $pdo = $db->prepare("INSERT INTO $table (club_id, target, created_at) VALUES (:club_id, :target, :created_at)");
    $pdo->execute([':club_id' => $club_id, ':target' => $target, ':created_at' => time()]);
    echo "Blocked $type: $target" . ($club ? " for club: $club" : "") . "\n";
}

function unblock($type, $target, $club = null) {
    global $db;
    $table = ($type == 'user') ? 'users_blocks' : 'instances_blocks';
    $club_id = null;
    if ($club) {
        $pdo = $db->prepare('SELECT cid FROM clubs WHERE name = :name');
        $pdo->execute([':name' => $club]);
        $club_id = $pdo->fetchColumn();
        if (!$club_id) {
            echo "Error: Club $club not found.\n";
            return;
        }
    }
    $pdo = $db->prepare("DELETE FROM $table WHERE target = :target AND club_id " . ($club_id ? "= :club_id" : "IS NULL"));
    $params = [':target' => $target];
    if ($club_id) {
        $params[':club_id'] = $club_id;
    }
    $pdo->execute($params);
    echo "Unblocked $type: $target" . ($club ? " for club: $club" : "") . "\n";
}

function listBlocks($club = null) {
    global $db;
    $club_id = null;
    if ($club) {
        $pdo = $db->prepare('SELECT cid FROM clubs WHERE name = :name');
        $pdo->execute([':name' => $club]);
        $club_id = $pdo->fetchColumn();
        if (!$club_id) {
            echo "Error: Club $club not found.\n";
            return;
        }
    }
    
    $tables = ['users_blocks', 'instances_blocks'];
    foreach ($tables as $table) {
        $type = ($table == 'users_blocks') ? 'User' : 'Instance';
        echo "$type blocks:\n";
        $pdo = $db->prepare("SELECT target FROM $table WHERE club_id " . ($club_id ? "= :club_id" : "IS NULL"));
        if ($club_id) {
            $pdo->execute([':club_id' => $club_id]);
        } else {
            $pdo->execute();
        }
        while ($row = $pdo->fetch(PDO::FETCH_ASSOC)) {
            echo "- " . $row['target'] . "\n";
        }
        echo "\n";
    }
}

function exportBlocks($club = null) {
    global $db;
    $club_id = null;
    if ($club) {
        $pdo = $db->prepare('SELECT cid FROM clubs WHERE name = :name');
        $pdo->execute([':name' => $club]);
        $club_id = $pdo->fetchColumn();
        if (!$club_id) {
            echo "Error: Club $club not found.\n";
            return;
        }
    }
    
    $prefix = $club ? $club . '_' : '';
    $users_file = fopen($prefix . 'users_blocks.txt', 'w');
    $instances_file = fopen($prefix . 'instances_blocks.txt', 'w');
    
    $tables = ['users_blocks', 'instances_blocks'];
    foreach ($tables as $table) {
        $file = ($table == 'users_blocks') ? $users_file : $instances_file;
        $pdo = $db->prepare("SELECT target FROM $table WHERE club_id " . ($club_id ? "= :club_id" : "IS NULL"));
        if ($club_id) {
            $pdo->execute([':club_id' => $club_id]);
        } else {
            $pdo->execute();
        }
        while ($row = $pdo->fetch(PDO::FETCH_ASSOC)) {
            fwrite($file, $row['target'] . "\n");
        }
    }
    
    fclose($users_file);
    fclose($instances_file);
    echo "Blocks exported to " . $prefix . "users_blocks.txt and " . $prefix . "instances_blocks.txt\n";
}

function importBlocks($type, $file_path, $club = null) {
    global $db;
    $club_id = null;
    if ($club) {
        $pdo = $db->prepare('SELECT cid FROM clubs WHERE name = :name');
        $pdo->execute([':name' => $club]);
        $club_id = $pdo->fetchColumn();
        if (!$club_id) {
            echo "Error: Club $club not found.\n";
            return;
        }
    }
    
    if (!file_exists($file_path)) {
        echo "Error: File $file_path not found.\n";
        return;
    }
    
    $db->beginTransaction();
    
    try {
        $table = ($type === 'user') ? 'users_blocks' : 'instances_blocks';
        if ($club_id !== null) {
            $checkStmt = $db->prepare("SELECT id FROM $table WHERE club_id = :club_id AND target = :target");
        } else {
            $checkStmt = $db->prepare("SELECT id FROM $table WHERE club_id IS NULL AND target = :target");
        }
        $insertStmt = $db->prepare("INSERT INTO $table (club_id, target, created_at) 
                                    VALUES (:club_id, :target, :created_at)");
        $targets = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($targets as $target) {
            if ($club_id !== null) {
                $checkStmt->execute([':club_id' => $club_id, ':target' => $target]);
            } else {
                $checkStmt->execute([':target' => $target]);
            }
            if ($checkStmt->fetch()) {
                echo "Warning: Skipping existing rule: $target\n";
                continue;
            }
            
            $insertStmt->execute([':club_id' => $club_id, ':target' => $target, ':created_at' => time()]);
        }
        
        $db->commit();
        echo "Successfully imported block rules.\n";
    } catch (Exception $e) {
        $db->rollBack();
        echo "Error importing block rules: " . $e->getMessage() . "\n";
    }
}

function isBlocked($name, $clubs) {
    global $db;
    $instance = explode('@', $name)[1];

    $pdo = $db->prepare('SELECT 1 FROM instances_blocks WHERE club_id IS NULL AND (target = :target OR :instance LIKE CONCAT("%.", target)) LIMIT 1');
    $pdo->execute([':target' => $instance, ':instance' => $instance]);
    if ($pdo->fetchColumn()) {
        return true;
    }

    $pdo = $db->prepare('SELECT 1 FROM users_blocks WHERE club_id IS NULL AND target = :target LIMIT 1');
    $pdo->execute([':target' => $name]);
    if ($pdo->fetchColumn()) {
        return true;
    }

    foreach ($clubs as $club) {
        $pdo = $db->prepare('SELECT cid FROM clubs WHERE name = :name');
        $pdo->execute([':name' => $club]);
        $club_id = $pdo->fetchColumn();

        if ($club_id) {
            $pdo = $db->prepare('SELECT 1 FROM instances_blocks WHERE club_id = :club_id AND (target = :target OR :instance LIKE CONCAT("%.", target)) LIMIT 1');
            $pdo->execute([':club_id' => $club_id, ':target' => $instance, ':instance' => $instance]);
            if ($pdo->fetchColumn()) {
                return true;
            }

            $pdo = $db->prepare('SELECT 1 FROM users_blocks WHERE club_id = :club_id AND target = :target LIMIT 1');
            $pdo->execute([':club_id' => $club_id, ':target' => $name]);
            if ($pdo->fetchColumn()) {
                return true;
            }
        }
    }

    return false;
}
