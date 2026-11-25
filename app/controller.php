<?php

function controller() {
    global $db, $ver, $base, $config;

    $path = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
    $routes = ['/' => 'index', '/inbox' => 'inbox', '/nodeinfo/2.0' => 'nodeinfo2', '/.well-known/nodeinfo' => 'nodeinfo', '/.well-known/webfinger' => 'webfinger'];
    $to = $routes[$path] ?? '';
    $method = $_SERVER['REQUEST_METHOD']; $part = '';
    if (preg_match('~^/club/([a-zA-Z_][a-zA-Z0-9_]{1,29})(?:/(inbox|outbox|following|followers|collections/(?:featured|tags|devices)))?$~D', $path, $match)) {
        $to = 'club'; $name = $match[1]; $part = $match[2] ?? '';
    }
    if ($to === 'webfinger') header('Access-Control-Allow-Origin: *');
    $inbox = $to === 'inbox' || ($to === 'club' && $part === 'inbox');
    if ($to !== '' && !in_array($method, ['GET', 'HEAD'], true) && !($inbox && $method === 'POST')) {
        header('Allow: GET, HEAD'.($inbox ? ', POST' : ''));
        return Club_HTTP_Respond(['message' => 'Method not allowed'], 'json', 405);
    }
    if ($inbox && $method === 'POST') return Club_Inbox_Receive($name ?? null);

    switch ($to) {

        case 'club':
            $page = Club_HTTP_Page($_GET, $part === 'outbox');
            if (($part === '' || $part === 'outbox') && $page === false) return Club_HTTP_Respond(['message' => 'Invalid page cursor'], 'json', 400);
            if ($club = Club_Group_Ensure($name, $part === '')) {
                $club_url = $base.'/club/'.$club;
                $system = Club_Group_System($club);
                if ($part !== '') switch ($part) {
                    case 'inbox': ActivityPub_Collection($club_url.'/inbox'); break;
                    case 'outbox':
                        if (isset($_GET['page'])) {
                            list($rows, $newer, $older) = Club_Group_Page($club, $page);
                            $tail = $page['tail']; $max = $page['max']; $min = $page['min'];

                            $self = $club_url.'/outbox?page='.($tail ? 'last' : 'true');
                            if ($max) $self .= '&max='.$max[0].'.'.$max[1];
                            elseif ($min) $self .= '&min='.$min[0].'.'.$min[1];
                            $arr = ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $self, 'type' => 'OrderedCollectionPage', 'partOf' => $club_url.'/outbox', 'orderedItems' => []];
                            if ($rows) {
                                $head = $rows[0]; $foot = $rows[count($rows) - 1];
                                if ($older) $arr['next'] = $club_url.'/outbox?page=true&max='.$foot['timestamp'].'.'.$foot['id'];
                                if ($newer) $arr['prev'] = $club_url.'/outbox?page=true&min='.$head['timestamp'].'.'.$head['id'];
                            }
                            foreach ($rows as $announce) {
                                $arr['orderedItems'][] = ['@context' => 'https://www.w3.org/ns/activitystreams'] +
                                    Club_Group_Announce($club, $announce['activity'], $announce['announced'], $announce['actor'], $announce['object']);
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
                    case 'collections/featured': ActivityPub_Collection($club_url.'/'.$part); break;
                    case 'collections/tags': case 'collections/devices': ActivityPub_Collection($club_url.'/'.$part, ['type' => 'Collection']); break;
                } else {
                    $actor = Club_Group_Actor($club, $pdo);
                    header('Vary: Accept');
                    if ($system || Club_HTTP_Accept_ActivityPub($_SERVER['HTTP_ACCEPT'] ?? '')) Club_HTTP_Respond($actor, 'activity+json');
                    else Club_Template_Render('profile', ['club' => $club, 'nickname' => $actor['name'], 'summary' => $actor['summary'], 'row' => $pdo, 'page' => $page]);
                }
            } else Club_HTTP_Respond(['message' => 'User not found'], 'json', 404); break;

        case 'inbox': ActivityPub_Collection($base.'/inbox'); break;

        case 'nodeinfo': Club_HTTP_Respond(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => $base.'/nodeinfo/2.0']]]); break;

        case 'nodeinfo2':
            $pdo = $db->prepare('select (select count(cid) from clubs) as clubs, (select count(id) from announces) as announces, (select count(distinct cid) from announces'.
                ' where timestamp >= :month) as activeMonth, (select count(distinct cid) from announces where timestamp >= :halfyear) as activeHalfyear');
            $pdo->execute([':month' => time()-86400*30, ':halfyear' => time()-86400*30*6]);
            $usage = $pdo->fetch(PDO::FETCH_ASSOC);
            Club_HTTP_Respond([
                'version' => '2.0',
                'software' => ['name' => 'wxwclub', 'version' => $ver],
                'protocols' => ['activitypub'],
                'services' => ['inbound' => [], 'outbound' => []],
                'openRegistrations' => $config['club']['open-registrations'],
                'usage' => [
                    'users' => [
                        'total' => (int)$usage['clubs'],
                        'activeMonth' => (int)$usage['activeMonth'],
                        'activeHalfyear' => (int)$usage['activeHalfyear']
                    ],
                    'localPosts' => (int)$usage['announces']
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
                ]], 'jrd+json');
            } else Club_HTTP_Respond(['message' => 'User not found'], 'json', 404); break;

        case 'index': Club_Template_Render('index'); break;

        default: Club_HTTP_Respond(['message' => 'Error: Route Not Found!'], 'json', 404); break;
    }
}
