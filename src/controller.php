<?php require('function.php');

function controller() {
    global $db, $ver, $base, $config;
    $public_streams = 'https://www.w3.org/ns/activitystreams#Public';
    
    $router = [
        '/' => ['to' => 'index', 'strict' => 1],
        '/club' => ['to' => 'club', 'strict' => 0],
        '/inbox' => ['to' => 'inbox', 'strict' => 1],
        '/nodeinfo/2.0' => ['to' => 'nodeinfo2', 'strict' => 1],
        '/.well-known/nodeinfo' => ['to' => 'nodeinfo', 'strict' => 1],
        '/.well-known/webfinger' => ['to' => 'webfinger', 'strict' => 1]
    ];
    
    $uri = explode('?', $_SERVER['REQUEST_URI'])[0];
    foreach ($router as $k => $v)
        if ($k == ($v['strict'] ? $uri : substr($uri, 0, strlen($k)))) $to = $v['to'];
    
    switch ($to) {
        
        case 'club':
            $club_url = $base . '/club/' . ($club = ($uri = explode('/', $uri))[2]);
            if (Club_Exist($club)) { switch ($uri[3]) {
                
                case 'inbox':
                    if ($_SERVER['REQUEST_METHOD'] == 'POST' && $_SERVER['HTTP_CONTENT_TYPE'] == 'application/activity+json') {
                        $jsonld = json_decode(($input = file_get_contents('php://input')), 1);
                        if (isset($jsonld['actor']) && parse_url($jsonld['actor'])['host'] != $config['base'] &&
                        ($jsonld['type'] == 'Delete' || $actor = Club_Get_Actor($jsonld['actor']))) { switch ($jsonld['type']) {
                            case 'Create':
                                $pdo = $db->prepare('select `id` from `activitys` where `object` = :object');
                                $pdo->execute([':object' => $jsonld['object']['id']]);
                                if (!$pdo->fetch(PDO::FETCH_ASSOC)) {
                                    if (in_array($public_streams, $jsonld['to']) || in_array($public_streams, $jsonld['cc'])) {
                                        list($msec, $time) = explode(' ', microtime());
                                        $activity_id = (string)sprintf('%.0f', (floatval($msec) + floatval($time)) * 1000);
                                        $outbox = json_encode([
                                            '@context' => 'https://www.w3.org/ns/activitystreams',
                                            'id' => $club_url.'/announce/'.$activity_id.'/activity',
                                            'type' => 'Announce',
                                            'actor' => $club_url,
                                            'published' => date('Y-m-d\TH:i:s\Z', $time),
                                            'to' => [$club_url.'/followers'],
                                            'cc' => [$jsonld['actor'], $public_streams],
                                            'object' => $jsonld['object']['id']
                                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                        $pdo = $db->prepare('select distinct u.shared_inbox from `followers` `f` join `clubs` `c` on f.cid = c.cid join `users` `u` on f.uid = u.uid where c.name = :club');
                                        $pdo->execute([':club' => $club]);
                                        foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $inbox) ActivityPub_POST($inbox, $club, $outbox);
                                        $pdo = $db->prepare('insert into `activitys`(`cid`,`uid`,`type`,`activity_id`,`object`)'.
                                            ' select `cid`, :uid as `uid`, :type as `type`, :activity_id as `activity_id`, :object as `object` from `clubs` where `name` = :club');
                                        $pdo->execute(['club' => $club, 'uid' => $actor['uid'], 'type' => 'Announce', 'activity_id' => $activity_id, 'object' => $jsonld['object']['id']]);
                                    }
                                } break;
                            
                            case 'Follow':
                                $pdo = $db->prepare('insert into `followers`(`cid`,`uid`) select `cid`, :uid as `uid` from `clubs` where `name` = :club');
                                $pdo->execute([':club' => $club, ':uid' => $actor['uid']]);
                                
                                ActivityPub_POST($actor['inbox'], $club, json_encode([
                                    '@context' => 'https://www.w3.org/ns/activitystreams',
                                    'id' => $club_url.'#accepts/follows/'.$actor['uid'],
                                    'type' => 'Accept',
                                    'actor' => $club_url,
                                    'object' => [
                                        'id' => $jsonld['id'],
                                        'type' => 'Follow',
                                        'actor' => $jsonld['actor'],
                                        'object' => $club_url
                                    ]
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); break;
                            
                            case 'Undo':
                                switch ($jsonld['object']['type']) {
                                    case 'Follow':
                                        $club = explode('/', $jsonld['object']['object'])[4];
                                        $pdo = $db->prepare('delete from `followers` where `cid` in (select cid from `clubs` where `name` = :club) and `uid` in (select uid from `users` where `actor` = :actor)');
                                        $pdo->execute([':club' => $club, ':actor' => $jsonld['actor']]); break;
                                        
                                    default: break;
                                } break;
                            
                            case 'Delete':
                                switch ($jsonld['object']['type']) {
                                    case 'Tombstone':
                                        $pdo = $db->prepare('select `id`,`activity_id`,`object`,`create_time` from `activitys` where `object` = :object');
                                        $pdo->execute([':object' => $jsonld['object']['id']]);
                                        if ($activity = $pdo->fetch(PDO::FETCH_ASSOC)) {
                                            $outbox = json_encode([
                                                '@context' => 'https://www.w3.org/ns/activitystreams',
                                                'id' => $club_url.'/announce/'.$activity['activity_id'].'/undo',
                                                'type' => 'Undo',
                                                'actor' => $club_url,
                                                'to' => $public_streams,
                                                'object' => [
                                                    'id' => $club_url.'/announce/'.$activity['activity_id'].'/activity',
                                                    'type' => 'Announce',
                                                    'actor' => $club_url,
                                                    'published' => date('Y-m-d\TH:i:s\Z', strtotime($activity['create_time'])),
                                                    'to' => [$club_url.'/followers'],
                                                    'cc' => [
                                                        $jsonld['actor'],
                                                        $public_streams
                                                    ],
                                                    'object' => $activity['object']
                                                ]
                                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                            $pdo = $db->prepare('select distinct u.shared_inbox from `followers` `f` join `clubs` `c` on f.cid = c.cid join `users` `u` on f.uid = u.uid where c.name = :club');
                                            $pdo->execute([':club' => $club]);
                                            foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $inbox) ActivityPub_POST($inbox, $club, $outbox);
                                            $pdo = $db->prepare('delete from `activitys` where `id` = :activity');
                                            $pdo->execute([':activity' => $activity['id']]);
                                        } break;
                                    
                                    case null:
                                        if ($jsonld['actor'] == $jsonld['object']) {
                                            $pdo = $db->prepare('delete from `users` where `actor` = :actor');
                                            $pdo->execute([':actor' => $jsonld['actor']]);
                                        } break;
                                    
                                    default: break;
                                } break;
                            
                            default: break;
                        }} else json_output(['message' => 'Request is invalid'], 0, 400);
                    } else header('Location: '.$club_url); break;
                
                case null:
                    if (strpos($_SERVER['HTTP_ACCEPT'], 'json')) {
                        $pdo = $db->prepare('select `nickname`,`summary`,`infoname_cn`,`infoname_en`,`avatar`,`banner`,`public_key`,`create_time` from `clubs` where `name` = :club');
                        $pdo->execute([':club' => $club]);
                        $pdo = $pdo->fetch(PDO::FETCH_ASSOC);
                        json_output([
                            '@context' => [
                                'https://www.w3.org/ns/activitystreams',
                                'https://w3id.org/security/v1'
                            ],
                            'id' => $club_url,
                            'type' => 'Group',
                            'preferredUsername' => $club,
                            'name' => $pdo['nickname'] ?: $club.' 组',
                            'summary' => $pdo['summary'] ?: str_replace(
                                [':infoname_cn:', ':infoname_en:', ':local_domain:'],
                                [$pdo['infoname_cn']?:$club, $pdo['infoname_en']?:$club, $config['base']],
                                $config['default']['summary']),
                            'icon' => [
                                'type' => 'Image',
                                'url' => $pdo['avatar'] ?: $config['default']['avatar'],
                                'sensitive' => false,
                                'name' => null
                            ],
                            'image' => [
                                'type' => 'Image',
                                'url' => $pdo['banner'] ?: $config['default']['banner'],
                                'sensitive' => false,
                                'name' => null
                            ],
                            'inbox' => $club_url.'/inbox',
                            'published' => date('Y-m-d\TH:i:s\Z', strtotime($pdo['create_time'])),
                            'publicKey' => [
                                'id' => $club_url.'#main-key',
                                'owner' => $club_url,
                                'publicKeyPem' => $pdo['public_key']
                            ]
                        ], 2);
                    } else {
                        $pdo = $db->prepare('select `cid`,`nickname`,`summary`,`infoname_cn`,`infoname_en`,`avatar`,`banner`,`create_time` from `clubs` where `name` = :club');
                        $pdo->execute([':club' => $club]);
                        $pdo = $pdo->fetch(PDO::FETCH_ASSOC);
                        echo '<title>'.($pdo['nickname']?:$club).' (@'.$club.'@'.$config['base'].')</title>';
                        echo '<style>.info::before{content:"";background:url('.($pdo['banner'] ?: $config['default']['banner']).') no-repeat center;';
                        echo 'background-size:cover;opacity:0.35;z-index:-1;position:absolute;width:680px;height:188px;top:0px;left:0px;border-radius:8px;}</style>';
                        echo '<div class="info"><img src="'.($pdo['avatar'] ?: $config['default']['avatar']).'" width="50" /><p style="line-height:1px"><br></p>';
                        echo '<h3 style="position:absolute;top:10px;left:68px">'.($pdo['nickname']?:$club).' (@'.$club.'@'.$config['base'].')</h3>';
                        echo '<div style="font-size:14px;line-height:10px">'.($pdo['summary'] ?: str_replace(
                            [':infoname_cn:',':infoname_en:',':local_domain:'],
                            [$pdo['infoname_cn']?:$club, $pdo['infoname_en']?:$club, $config['base']],
                            $config['default']['summary'])).'</div><p style="line-height:1px"><br></p></div>';
                        echo '<div style="font-size:14px;line-height:10px"><p>近 10 次活动：</p>';
                        $activitys = $db->prepare('select `type`, `object`, `create_time` from `activitys` where `cid` = :cid order by `create_time` desc');
                        $activitys->execute([':cid' => $pdo['cid']]);
                        foreach ($activitys->fetchAll(PDO::FETCH_ASSOC) as $activity)
                            echo '<p>',$activity['create_time'],': ',$activity['type'],' <a target="_blank" href="',$activity['object'],'">',$activity['object'],'</a></p>';
                        echo '</div>';
                    } break;
                
                default: json_output(['message' => 'Error: Route Not Found!'], 0, 404); break;
                
            }} else json_output(['message' => 'User not found'], 0, 404); break;
        
        case 'nodeinfo':
            json_output(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => $base.'/nodeinfo/2.0']]]); break;
        
        case 'nodeinfo2':
            json_output([
                'version' => '2.0',
                'software' => ['name' => 'wxwClub', 'version' => $ver],
                'protocols' => ['activitypub'],
                'services' => ['inbound' => [], 'outbound' => []],
                'openRegistrations' => $config['openRegistrations'],
                'usage' => ['users'=> []],
                'metadata' => [
                    'nodeName' => $config['nodeName'],
                    'nodeDescription' => $config['nodeDescription'],
                    'maintainer' => $config['maintainer'],
                    'repositoryUrl' => '',
                    'feedbackUrl' => ''
                ]
            ]); break;
        
        case 'webfinger':
            $resource = $_GET['resource'];
            if (!preg_match('/^acct:([^@]+)@(.+)$/', $resource, $matches))
                json_output(['message' => 'Resource is invalid'], 0, 400);
            
            $resource_host = $matches[2];
            $resource_identifier = $matches[1];
    		
    		if ($resource_host != $config['base'])
    		    json_output(['message' => 'Resource host does not match'], 0, 404);
    		
    		$club_url = $base.'/club/'.$resource_identifier;
    		if (Club_Exist($resource_identifier)) {
    		    json_output([
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
    		} else json_output(['message' => 'User not found'], 0, 404); break;
        
        case 'index':
            echo '<title>'.$config['nodeName'].'</title>';
            echo '<h3>'.$config['nodeName'].' (wxwClub/'.$ver.')</h3><p>'.$config['nodeDescription'].'</p><p><br></p>';
            echo 'Maintainer: '.$config['maintainer']['name'].' (mail: '.$config['maintainer']['email'].')'; break;
        
        default: json_output(['message' => 'Error: Route Not Found!'], 0, 404); break;
    }
}
