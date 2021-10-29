<?php require('function.php');

function controller() {
    global $db, $ver, $base, $config, $public_streams;
    
    $router = [
        '/' => ['to' => 'index', 'strict' => 1],
        '/club' => ['to' => 'club', 'strict' => 0],
        '/inbox' => ['to' => 'inbox', 'strict' => 1],
        '/nodeinfo/2.0' => ['to' => 'nodeinfo2', 'strict' => 1],
        '/.well-known/nodeinfo' => ['to' => 'nodeinfo', 'strict' => 1],
        '/.well-known/webfinger' => ['to' => 'webfinger', 'strict' => 1]
    ];
    
    $to = ''; $uri = explode('?', $_SERVER['REQUEST_URI'])[0];
    foreach ($router as $k => $v)
        if ($k == ($v['strict'] ? $uri : substr($uri, 0, strlen($k)))) $to = $v['to'];
    
    switch ($to) {
        
        case 'club':
            $club_url = $base . '/club/' . ($club = ($uri = explode('/', $uri))[2]);
            if (Club_Exist($club)) {
                if (isset($uri[3])) switch ($uri[3]) {
                    case 'inbox':
                        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                            $jsonld = json_decode($input = file_get_contents('php://input'), 1);
                            
                            if (isset($jsonld['actor']) && parse_url($jsonld['actor'])['host'] != $config['base'] &&
                            ($jsonld['type'] == 'Delete' || $actor = Club_Get_Actor($club, $jsonld['actor']))) {
                                if ($config['nodeDebugging'] && !($jsonld['type'] == 'Delete' && $jsonld['actor'] == $jsonld['object'])) {
                                    $file_name = date('Y-m-d_H:i:s_').$club.'_'.$jsonld['type'];
                                    file_put_contents('inbox_logs/'.$file_name.'_input.json', $input);
                                    file_put_contents('inbox_logs/'.$file_name.'_server.json', Club_Json_Encode($_SERVER));
                                }
                                switch ($jsonld['type']) {
                                    case 'Create': Club_Announce_Process($jsonld); break;
                                    case 'Follow':
                                        $pdo = $db->prepare('insert into `followers`(`cid`,`uid`,`timestamp`) select `cid`, :uid as `uid`, :timestamp as `timestamp` from `clubs` where `name` = :club');
                                        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':timestamp' => time()]);
                                        $pdo = $db->prepare('select f.id from `followers` as f left join `clubs` as `c` on f.cid = c.cid where f.uid = :uid and c.name = :club');
                                        $pdo->execute([':club' => $club, ':uid' => $actor['uid']]);
                                        if ($follow_id = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
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
                                        } break;
                                    case 'Undo':
                                        switch ($jsonld['object']['type']) {
                                            case 'Follow':
                                                $club = explode('/', $jsonld['object']['object'])[4];
                                                $pdo = $db->prepare('delete from `followers` where `cid` in'.
                                                    ' (select cid from `clubs` where `name` = :club)'.
                                                    ' and `uid` in (select uid from `users` where `actor` = :actor)');
                                                $pdo->execute([':club' => $club, ':actor' => $jsonld['actor']]); break;
                                                
                                            default: break;
                                        } break;
                                    case 'Delete':
                                        if (isset($jsonld['object']['type'])) switch ($jsonld['object']['type']) {
                                            case 'Tombstone': Club_Tombstone_Process($jsonld); break;
                                            default: break;
                                        } else {
                                            if ($jsonld['actor'] == $jsonld['object']) {
                                                $pdo = $db->prepare('delete from `users` where `actor` = :actor');
                                                $pdo->execute([':actor' => $jsonld['actor']]);
                                            }
                                        } break;
                                    default: break;
                                }
                            } else Club_Json_Output(['message' => 'Request is invalid'], 0, 400);
                        } else header('Content-type: application/activity+json'); break;
                    
                    case 'outbox':
                        if (isset($_GET['page'])) {
                            $arr = [
                                '@context' => 'https://www.w3.org/ns/activitystreams',
                                'id' => $club_url.'/outbox?page='.($page = (int)$_GET['page']),
                                'type' => 'OrderedCollectionPage',
                                'next' => $club_url.'/outbox?page=',
                                'prev' => $club_url.'/outbox?page=',
                                'partOf' => $club_url.'/outbox',
                                'orderedItems' => []
                            ];
                            if ($page < 0) {
                                $order = '';
                                $arr['next'] .= $page - 1;
                                $arr['prev'] .= ($page == -1 ? $page - 1 : $page)  + 1;
                                $page = abs($page);
                            } else {
                                $order = ' desc';
                                if ($page == 0) $page = 1;
                                $arr['next'] .= $page + 1;
                                $arr['prev'] .= $page - 1;
                            }
                            $pdo = $db->prepare('select u.actor, a.activity, b.object, b.timestamp from `announces` `a`'.
                            ' left join `clubs` `c` on a.cid = c.cid'.
                            ' left join `users` `u` on a.uid = u.uid'.
                            ' left join `activities` `b` on a.activity = b.id'.
                            ' where c.name = :club order by b.timestamp'.$order.' limit '.(($page-1)*20).', 20');
                            $pdo->execute([':club' => $club]);
                            foreach ($pdo->fetchAll(PDO::FETCH_ASSOC) as $announce) {
                                $arr['orderedItems'][] = [
                                    '@context' => 'https://www.w3.org/ns/activitystreams',
                                    'id' => $club_url.'/activity#'.$announce['activity'].'/announce',
                                    'type' => 'Announce',
                                    'actor' => $club_url,
                                    'published' => date('Y-m-d\TH:i:s\Z', $announce['timestamp']),
                                    'to' => [$club_url.'/followers'],
                                    'cc' => [$announce['actor'], $public_streams],
                                    'object' => $announce['object']
                                ];
                            } Club_Json_Output($arr, 2);
                        } else {
                            $pdo = $db->prepare('select count(a.id) from `announces` `a` left join `clubs` `c` on a.cid = c.cid where c.name = :club');
                            $pdo->execute([':club' => $club]);
                            $count = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0);
                            Club_Get_OrderedCollection($club_url.'/outbox', [
                                'totalItems' => $count,
                                'first' => $club_url.'/outbox?page=1',
                                'last' => $club_url.'/outbox?page=-1',
                            ]);
                        } break;
                    
                    case 'following': Club_Get_OrderedCollection($club_url.'/following'); break;
                    case 'followers': Club_Get_OrderedCollection($club_url.'/followers'); break;
                    case 'collections':
                        if (isset($uri[4])) switch ($uri[4]) {
                            case 'featured': Club_Get_OrderedCollection($club_url.'/collections/featured'); break;
                            case 'tags': Club_Get_OrderedCollection($club_url.'/collections/tags', ['type' => 'Collection']); break;
                            case 'devices': Club_Get_OrderedCollection($club_url.'/collections/devices', ['type' => 'Collection']); break;
                            default: break;
                        } break;
                    default: Club_Json_Output(['message' => 'Error: Route Not Found!'], 0, 404); break;
                } else {
                    $pdo = $db->prepare('select `cid`,`nickname`,`infoname`,`summary`,`avatar`,`banner`,`public_key`,`timestamp` from `clubs` where `name` = :club');
                    $pdo->execute([':club' => $club]);
                    $pdo = $pdo->fetch(PDO::FETCH_ASSOC);
                    $nametag = array_merge($config['default']['infoname'], json_decode($pdo['infoname'], 1) ?: []);
                    $summary = $pdo['summary'] ?: Club_NameTag_Render($club, $config['default']['summary'], $nametag);
                    $nickname = $pdo['nickname'] ?: Club_NameTag_Render($club, $config['default']['nickname'], $nametag);
                    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'json')) {
                        Club_Json_Output([
                            '@context' => [
                                'https://www.w3.org/ns/activitystreams',
                                'https://w3id.org/security/v1',
                                [
                                    'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
                                    'toot' => 'http://joinmastodon.org/ns#',
                                    'featured' => ['@id' => 'toot:featured', '@type' => '@id'],
                                    'featuredTags' => ['@id' => 'toot:featuredTags', '@type' => '@id'],
                                    'alsoKnownAs' => ['@id' => 'as:alsoKnownAs', '@type' => '@id'],
                                    'movedTo' => ['@id' => 'as:movedTo', '@type' => '@id'],
                                    'schema' => 'http://schema.org#',
                                    'PropertyValue' => 'schema:PropertyValue',
                                    'value' => 'schema:value',
                                    'IdentityProof' => 'toot:IdentityProof',
                                    'discoverable' => 'toot:discoverable',
                                    'Device' => 'toot:Device',
                                    'Ed25519Signature' => 'toot:Ed25519Signature',
                                    'Ed25519Key' => 'toot:Ed25519Key',
                                    'Curve25519Key' => 'toot:Curve25519Key',
                                    'EncryptedMessage' => 'toot:EncryptedMessage',
                                    'publicKeyBase64' => 'toot:publicKeyBase64',
                                    'deviceId' => 'toot:deviceId',
                                    'claim' => ['@type' => '@id', '@id' => 'toot:claim'],
                                    'fingerprintKey' => ['@type' => '@id', '@id' => 'toot:fingerprintKey'],
                                    'identityKey' => ['@type' => '@id', '@id' => 'toot:identityKey'],
                                    'devices' => ['@type' => '@id', '@id' => 'toot:devices'],
                                    'messageFranking' => 'toot:messageFranking',
                                    'messageType' => 'toot:messageType',
                                    'cipherText' => 'toot:cipherText',
                                    'suspended' => 'toot:suspended',
                                    'Emoji' => 'toot:Emoji',
                                    'focalPoint' => ['@container' => '@list', '@id' => 'toot:focalPoint']
                                ]
                            ],
                            'id' => $club_url,
                            'type' => 'Group',
                            'following' => $club_url.'/following',
                            'followers' => $club_url.'/followers',
                            'inbox' => $club_url.'/inbox',
                            'outbox' => $club_url.'/outbox',
                            'featured' => $club_url.'/collections/featured',
                            'featuredTags' => $club_url.'/collections/tags',
                            'preferredUsername' => $club,
                            'name' => $nickname,
                            'summary' => $summary,
                            'url' => $club_url,
                            'manuallyApprovesFollowers' => false,
                            'discoverable' => false,
                            'published' => date('Y-m-d\TH:i:s\Z', $pdo['timestamp']),
                            'devices' => $club_url.'/collections/devices',
                            'publicKey' => [
                                'id' => $club_url.'#main-key',
                                'owner' => $club_url,
                                'publicKeyPem' => $pdo['public_key']
                            ],
                            'tag' => [],
                            'attachment' => [],
                            'endpoints' => ['sharedInbox' => $base.'/inbox'],
                            'icon' => [
                                'type' => 'Image',
                                'url' => $pdo['avatar'] ?: $config['default']['avatar']
                            ],
                            'image' => [
                                'type' => 'Image',
                                'url' => $pdo['banner'] ?: $config['default']['banner']
                            ]
                        ], 2);
                    } else {
                        echo '<title>',$nickname,' (@',$club,'@',$config['base'],')</title>',
                            '<style>.info::before{content:"";background:url(',($pdo['banner'] ?: $config['default']['banner']),') no-repeat center;',
                            'background-size:cover;opacity:0.35;z-index:-1;position:absolute;width:680px;height:188px;top:0px;left:0px;border-radius:8px;}</style>',
                            '<div class="info"><img src="',($pdo['avatar'] ?: $config['default']['avatar']),'" width="50" /><p style="line-height:1px"><br></p>',
                            '<h3 style="position:absolute;top:10px;left:68px">',$nickname,' (@',$club,'@',$config['base'],')</h3>',
                            '<div style="font-size:14px;line-height:10px">',$summary,'</div><p style="line-height:1px"><br></p></div>',
                            '<div style="font-size:14px;line-height:10px"><p>近 10 次活动：</p>';
                        $activities = $db->prepare('select u.name, a.content, a.timestamp from `announces` as `a` left join `users` as `u` on a.uid = u.uid where `cid` = :cid order by `timestamp` desc');
                        $activities->execute([':cid' => $pdo['cid']]);
                        foreach ($activities->fetchAll(PDO::FETCH_ASSOC) as $activity)
                            echo '<p>[',date('Y-m-d H:i:s', $activity['timestamp']),'] ',$activity['name'],': ',$activity['content'],'</p>';
                        echo '</div>';
                    }
                }
            } else Club_Json_Output(['message' => 'User not found'], 0, 404); break;
        
        case 'inbox':
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $jsonld = json_decode($input = file_get_contents('php://input'), 1);
                if (isset($jsonld['actor']) && parse_url($jsonld['actor'])['host'] != $config['base']) {
                    if ($config['nodeDebugging'] && !($jsonld['type'] == 'Delete' && $jsonld['actor'] == $jsonld['object'])) {
                        $file_name = date('Y-m-d_H:i:s').'_shared_inbox_'.$jsonld['type'];
                        file_put_contents('inbox_logs/'.$file_name.'_input.json', $input);
                        file_put_contents('inbox_logs/'.$file_name.'_server.json', Club_Json_Encode($_SERVER));
                    }
                    switch ($jsonld['type']) {
                        case 'Create': Club_Announce_Process($jsonld); break;
                        case 'Delete':
                            if (isset($jsonld['object']['type'])) switch ($jsonld['object']['type']) {
                                case 'Tombstone': Club_Tombstone_Process($jsonld); break;
                                default: break;
                            } else {
                                if ($jsonld['actor'] == $jsonld['object']) {
                                    $pdo = $db->prepare('delete from `users` where `actor` = :actor');
                                    $pdo->execute([':actor' => $jsonld['actor']]);
                                } else {
                                    $jsonld['object'] = ['id' => $jsonld['object']];
                                    Club_Tombstone_Process($jsonld);
                                }
                            } break;
                        default: break;
                    }
                }
            } else header('Content-type: application/activity+json'); break;
        
        case 'nodeinfo':
            Club_Json_Output(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => $base.'/nodeinfo/2.0']]]); break;
        
        case 'nodeinfo2':
            Club_Json_Output([
                'version' => '2.0',
                'software' => ['name' => 'wxwClub', 'version' => $ver],
                'protocols' => ['activitypub'],
                'services' => ['inbound' => [], 'outbound' => []],
                'openRegistrations' => $config['openRegistrations'],
                'usage' => ['users'=> []],
                'metadata' => [
                    'nodeName' => $config['nodeName'],
                    'nodeDescription' => $config['nodeDescription'],
                    'maintainer' => $config['nodeMaintainer'],
                    'repositoryUrl' => '',
                    'feedbackUrl' => ''
                ]
            ]); break;
        
        case 'webfinger':
            $resource = $_GET['resource'];
            if (!preg_match('/^acct:([^@]+)@(.+)$/', $resource, $matches))
                Club_Json_Output(['message' => 'Resource is invalid'], 0, 400);
            
            $resource_host = $matches[2];
            $resource_identifier = $matches[1];
    		
    		if ($resource_host != $config['base'])
    		    Club_Json_Output(['message' => 'Resource host does not match'], 0, 404);
    		
    		$club_url = $base.'/club/'.$resource_identifier;
    		if (Club_Exist($resource_identifier)) {
    		    Club_Json_Output([
        		    'subject' => $resource,
        		    'aliases' => [$club_url],
        		    'links' => [
        		        [
        		            'rel' => 'http://webfinger.net/rel/profile-page',
        		            'type' => 'text/html',
        		            'href' => $club_url
        		        ], 
        		        [
        		            'rel' => 'self',
        		            'type' => 'application/activity+json',
        		            'href' => $club_url
        		        ]
        		]]);
    		} else Club_Json_Output(['message' => 'User not found'], 0, 404); break;
        
        case 'index':
            echo '<title>'.$config['nodeName'].'</title>';
            echo '<h3>'.$config['nodeName'].' (wxwClub/'.$ver.')</h3><p>'.$config['nodeDescription'].'</p><p><br></p>';
            echo 'Maintainer: '.$config['nodeMaintainer']['name'].' (mail: '.$config['nodeMaintainer']['email'].')'; break;
        
        default: Club_Json_Output(['message' => 'Error: Route Not Found!'], 0, 404); break;
    }
}
