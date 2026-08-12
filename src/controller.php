<?php require_once(__DIR__.'/function.php');

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
    foreach ($router as $k => $v) if ($k == ($v['strict'] ? $uri : substr($uri, 0, strlen($k)))) $to = $v['to'];

    switch ($to) {

        case 'club':
            if ($club = Club_Exist(($uri = explode('/', $uri))[2] ?? '')) {
                $club_url = $base.'/club/'.$club;
                $system = Club_System_Name($club);
                if (isset($uri[3])) switch ($uri[3]) {
                    case 'inbox':
                        if ($_SERVER['REQUEST_METHOD'] == 'POST') Club_Inbox_Process($club);
                        // actor 里 inbox 是公开地址，抓取方跟过来时得拿到能解析的集合，不能是空响应体
                        else Club_Get_OrderedCollection($club_url.'/inbox'); break;

                    case 'outbox':
                        if (isset($_GET['page'])) {
                            // 游标分页，page 只表示「这是一页」，方向由 max / min 决定
                            $tail = ($_GET['page'] ?? '') === 'last';
                            $max = Club_Cursor_Parse($_GET['max'] ?? '');
                            $min = Club_Cursor_Parse($_GET['min'] ?? '');
                            // 往新翻要先升序取够再倒回来，页内顺序始终是新到旧
                            $asc = $tail || $min;
                            $where = ''; $params = [':club' => $club];
                            if ($max) {
                                $where = ' and a.timestamp <= :ts and (a.timestamp < :ts or a.id < :id)';
                                $params[':ts'] = $max[0]; $params[':id'] = $max[1];
                            } elseif ($min) {
                                $where = ' and a.timestamp >= :ts and (a.timestamp > :ts or a.id > :id)';
                                $params[':ts'] = $min[0]; $params[':id'] = $min[1];
                            }
                            // 排序键取自 announces 才能吃到 (cid,timestamp) 索引，换成 b.timestamp 会 filesort
                            $pdo = $db->prepare('select a.id, a.timestamp, a.activity, u.actor, b.object, b.timestamp as `announced`'.
                            ' from `announces` `a` join `clubs` `c` on a.cid = c.cid left join `users` `u` on a.uid = u.uid left join `activities` `b` on a.activity = b.id'.
                            ' where c.name = :club'.$where.' order by a.timestamp '.($asc ? 'asc' : 'desc').', a.id '.($asc ? 'asc' : 'desc').' limit 20');
                            $pdo->execute($params);
                            $rows = $pdo->fetchAll(PDO::FETCH_ASSOC);
                            if ($asc) $rows = array_reverse($rows);

                            $self = $club_url.'/outbox?page='.($tail ? 'last' : 'true');
                            if ($max) $self .= '&max='.$max[0].'.'.$max[1];
                            elseif ($min) $self .= '&min='.$min[0].'.'.$min[1];
                            $arr = ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $self, 'type' => 'OrderedCollectionPage', 'partOf' => $club_url.'/outbox', 'orderedItems' => []];
                            if ($rows) {
                                $head = $rows[0]; $foot = $rows[count($rows) - 1];
                                // 取满一页才给 next，抓取方据此判断到底了
                                if (!$tail && count($rows) == 20) $arr['next'] = $club_url.'/outbox?page=true&max='.$foot['timestamp'].'.'.$foot['id'];
                                $arr['prev'] = $club_url.'/outbox?page=true&min='.$head['timestamp'].'.'.$head['id'];
                            }
                            foreach ($rows as $announce) {
                                $arr['orderedItems'][] = [
                                    '@context' => 'https://www.w3.org/ns/activitystreams',
                                    'id' => $club_url.'/activity#'.$announce['activity'].'/announce',
                                    'type' => 'Announce',
                                    'actor' => $club_url,
                                    'published' => gmdate('Y-m-d\TH:i:s\Z', $announce['announced']),
                                    'to' => [$club_url.'/followers'],
                                    'cc' => [$announce['actor'], $public_streams],
                                    'object' => $announce['object']
                                ];
                            } Club_Json_Output($arr, 'activity+json');
                        } else {
                            $pdo = $db->prepare('select count(a.id) from `announces` `a` join `clubs` `c` on a.cid = c.cid where c.name = :club');
                            $pdo->execute([':club' => $club]);
                            $count = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0);
                            Club_Get_OrderedCollection($club_url.'/outbox', ['totalItems' => $count, 'first' => $club_url.'/outbox?page=true', 'last' => $club_url.'/outbox?page=last']);
                        } break;

                    case 'following': Club_Get_OrderedCollection($club_url.'/following'); break;
                    case 'followers':
                        $pdo = $db->prepare('select count(f.id) from `followers` `f` left join `clubs` `c` on f.cid = c.cid where c.name = :club');
                        $pdo->execute([':club' => $club]);
                        $count = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0);
                        Club_Get_OrderedCollection($club_url.'/followers', ['totalItems' => $count]); break;
                    case 'collections':
                        switch ($uri[4] ?? '') {
                            case 'featured': Club_Get_OrderedCollection($club_url.'/collections/featured'); break;
                            case 'tags': Club_Get_OrderedCollection($club_url.'/collections/tags', ['type' => 'Collection']); break;
                            case 'devices': Club_Get_OrderedCollection($club_url.'/collections/devices', ['type' => 'Collection']); break;
                            default: Club_Json_Output(['message' => 'Error: Route Not Found!'], 'json', 404); break;
                        } break;
                    default: Club_Json_Output(['message' => 'Error: Route Not Found!'], 'json', 404); break;
                } else {
                    $pdo = $db->prepare('select `cid`,`nickname`,`infoname`,`summary`,`avatar`,`banner`,`public_key`,`timestamp` from `clubs` where `name` = :club');
                    $pdo->execute([':club' => $club]);
                    $pdo = $pdo->fetch(PDO::FETCH_ASSOC);
                    $nametag = array_merge($config['default']['infoname'], json_decode($pdo['infoname'], 1) ?: []);
                    $summary = $pdo['summary'] ?: Club_NameTag_Render($club, $config['default']['summary'], $nametag);
                    $nickname = $pdo['nickname'] ?: Club_NameTag_Render($club, $config['default']['nickname'], $nametag);
                    // 系统群组没有主页，浏览器访问也只给 actor
                    if ($system || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'json'))) {
                        Club_Json_Output([
                            '@context' => Club_Template('context'),
                            'id' => $club_url,
                            'type' => $system ? 'Service' : 'Group',
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
                            'manuallyApprovesFollowers' => $system,
                            'discoverable' => false,
                            'published' => gmdate('Y-m-d\TH:i:s\Z', $pdo['timestamp']),
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
                        ], 'activity+json');
                    } else Club_Template('profile', ['club' => $club, 'nickname' => $nickname, 'summary' => $summary, 'row' => $pdo]);
                }
            } else Club_Json_Output(['message' => 'User not found'], 'json', 404); break;

        case 'inbox': if ($_SERVER['REQUEST_METHOD'] == 'POST') Club_Inbox_Process(); else Club_Get_OrderedCollection($base.'/inbox'); break;

        case 'nodeinfo': Club_Json_Output(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => $base.'/nodeinfo/2.0']]]); break;

        case 'nodeinfo2':
            $pdo = $db->prepare('select (select count(cid) from clubs) as clubs, (select count(id) from announces) as announces, (select count(distinct cid) from announces'.
                ' where timestamp >= :month) as activeMonth, (select count(distinct cid) from announces where timestamp >= :halfyear) as activeHalfyear');
            $pdo->execute([':month' => time()-86400*30, ':halfyear' => time()-86400*30*6]);
            $usage = $pdo->fetch(PDO::FETCH_ASSOC);
            Club_Json_Output([
                'version' => '2.0',
                'software' => ['name' => 'wxwClub', 'version' => $ver],
                'protocols' => ['activitypub'],
                'services' => ['inbound' => [], 'outbound' => []],
                'openRegistrations' => $config['club']['open-registrations'],
                'usage' => [
                    'users' => [
                        'total' => $usage['clubs'] ?? null,
                        'activeMonth' => $usage['activeMonth'] ?? null,
                        'activeHalfyear' => $usage['activeHalfyear'] ?? null
                    ],
                    'localPosts' => $usage['announces'] ?? 0
                ],
                'metadata' => [
                    'nodeName' => $config['node']['name'],
                    'nodeDescription' => $config['node']['description'],
                    'maintainer' => $config['node']['maintainer'],
                    'repositoryUrl' => 'https://github.com/wxwmoe/wxwClub',
                    'feedbackUrl' => 'https://github.com/wxwmoe/wxwClub/issues/new'
                ]
            ]); break;

        case 'webfinger':
            // ?resource[]=x 这种数组参数直接当空处理，否则 preg_match 会收到数组报错
            $resource = is_scalar($_GET['resource'] ?? null) ? (string)$_GET['resource'] : '';
            Club_Log_Write('debug', 'webfinger', [$resource], $_SERVER);
            if (preg_match('/^acct:([^@]+)@(.+)$/', $resource, $matches)) {
                $resource_identifier = $matches[1];
                if (($resource_host = $matches[2]) != $config['base']) {
                    Club_Json_Output(['message' => 'Resource host does not match'], 'json', 404);
                    break;
                }
            } elseif (preg_match('/^acct:([a-zA-Z_][a-zA-Z0-9_]+)$/', $resource, $matches)) {
                $resource_host = $config['base'];
                $resource_identifier = $matches[1];
            } else {
                Club_Json_Output(['message' => 'Resource is invalid'], 'json', 400);
                break;
            }

            // 系统群组也要应答，对端验签时会拿 keyId 反查 WebFinger，404 会让私信被回 401；不进目录靠 actor 的 discoverable = false，不靠这里藏
            if ($club = Club_Exist($resource_identifier)) {
                $club_url = $base.'/club/'.$club;
                Club_Json_Output([
                    'subject' => 'acct:'.$club.'@'.$config['base'],
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
            } else Club_Json_Output(['message' => 'User not found'], 'json', 404); break;

        case 'index': Club_Template('index'); break;

        default: Club_Json_Output(['message' => 'Error: Route Not Found!'], 'json', 404); break;
    }
}
