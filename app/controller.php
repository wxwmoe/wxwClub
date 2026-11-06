<?php

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
            if ($club = Club_Group_Ensure(($uri = explode('/', $uri))[2] ?? '')) {
                $club_url = $base.'/club/'.$club;
                $system = Club_Group_System($club);
                if (isset($uri[3])) switch ($uri[3]) {
                    case 'inbox':
                        if ($_SERVER['REQUEST_METHOD'] == 'POST') Club_Inbox_Receive($club);
                        else ActivityPub_Collection($club_url.'/inbox'); break;

                    case 'outbox':
                        if (isset($_GET['page'])) {
                            $tail = ($_GET['page'] ?? '') === 'last';
                            $max = Club_HTTP_Cursor($_GET['max'] ?? '');
                            $min = Club_HTTP_Cursor($_GET['min'] ?? '');
                            $asc = $tail || $min;
                            $where = ''; $params = [':club' => $club];
                            if ($max) {
                                $where = ' and a.timestamp <= :ts and (a.timestamp < :ts or a.id < :id)';
                                $params[':ts'] = $max[0]; $params[':id'] = $max[1];
                            } elseif ($min) {
                                $where = ' and a.timestamp >= :ts and (a.timestamp > :ts or a.id > :id)';
                                $params[':ts'] = $min[0]; $params[':id'] = $min[1];
                            }
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
                            } Club_HTTP_Respond($arr, 'activity+json');
                        } else {
                            $pdo = $db->prepare('select count(a.id) from `announces` `a` join `clubs` `c` on a.cid = c.cid where c.name = :club');
                            $pdo->execute([':club' => $club]);
                            $count = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0);
                            ActivityPub_Collection($club_url.'/outbox', ['totalItems' => $count, 'first' => $club_url.'/outbox?page=true', 'last' => $club_url.'/outbox?page=last']);
                        } break;

                    case 'following': ActivityPub_Collection($club_url.'/following'); break;
                    case 'followers':
                        $pdo = $db->prepare('select count(f.id) from `followers` `f` left join `clubs` `c` on f.cid = c.cid where c.name = :club');
                        $pdo->execute([':club' => $club]);
                        $count = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0);
                        ActivityPub_Collection($club_url.'/followers', ['totalItems' => $count]); break;
                    case 'collections':
                        switch ($uri[4] ?? '') {
                            case 'featured': ActivityPub_Collection($club_url.'/collections/featured'); break;
                            case 'tags': ActivityPub_Collection($club_url.'/collections/tags', ['type' => 'Collection']); break;
                            case 'devices': ActivityPub_Collection($club_url.'/collections/devices', ['type' => 'Collection']); break;
                            default: Club_HTTP_Respond(['message' => 'Error: Route Not Found!'], 'json', 404); break;
                        } break;
                    default: Club_HTTP_Respond(['message' => 'Error: Route Not Found!'], 'json', 404); break;
                } else {
                    $actor = Club_Group_Actor($club, $pdo);
                    header('Vary: Accept');
                    if ($system || Club_HTTP_Accept_ActivityPub($_SERVER['HTTP_ACCEPT'] ?? '')) Club_HTTP_Respond($actor, 'activity+json');
                    else Club_Template_Render('profile', ['club' => $club, 'nickname' => $actor['name'], 'summary' => $actor['summary'], 'row' => $pdo]);
                }
            } else Club_HTTP_Respond(['message' => 'User not found'], 'json', 404); break;

        case 'inbox': if ($_SERVER['REQUEST_METHOD'] == 'POST') Club_Inbox_Receive(); else ActivityPub_Collection($base.'/inbox'); break;

        case 'nodeinfo': Club_HTTP_Respond(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => $base.'/nodeinfo/2.0']]]); break;

        case 'nodeinfo2':
            $pdo = $db->prepare('select (select count(cid) from clubs) as clubs, (select count(id) from announces) as announces, (select count(distinct cid) from announces'.
                ' where timestamp >= :month) as activeMonth, (select count(distinct cid) from announces where timestamp >= :halfyear) as activeHalfyear');
            $pdo->execute([':month' => time()-86400*30, ':halfyear' => time()-86400*30*6]);
            $usage = $pdo->fetch(PDO::FETCH_ASSOC);
            Club_HTTP_Respond([
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
            $resource = is_scalar($_GET['resource'] ?? null) ? (string)$_GET['resource'] : '';
            Club_Log_Write('debug', 'webfinger', [$resource], $_SERVER);
            $resource_identifier = ActivityPub_WebFinger_Resource($resource, $base, $config['base']);
            if ($resource_identifier === null) {
                Club_HTTP_Respond(['message' => 'Resource host does not match'], 'json', 404);
                break;
            } elseif ($resource_identifier === false) {
                Club_HTTP_Respond(['message' => 'Resource is invalid'], 'json', 400);
                break;
            }

            if ($club = Club_Group_Ensure($resource_identifier)) {
                $club_url = $base.'/club/'.$club;
                Club_HTTP_Respond([
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
            } else Club_HTTP_Respond(['message' => 'User not found'], 'json', 404); break;

        case 'index': Club_Template_Render('index'); break;

        default: Club_HTTP_Respond(['message' => 'Error: Route Not Found!'], 'json', 404); break;
    }
}
