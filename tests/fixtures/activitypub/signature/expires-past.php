<?php

/* (created) 还在窗口里，但对端自己声明的 (expires) 已经过去了。签了这一项就得认，它比本站的 300 秒更严。 */

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 40,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['algorithm' => 'hs2019', 'headers' => ['(request-target)', 'host', '(created)', '(expires)', 'digest'],
            'created' => time() - 100, 'expires' => time() - 10],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/expired/follow/1","type":"Follow","actor":"https://remote.example/users/expired","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 403,
        'follower_created' => false,
        'delivery_enqueued' => false
    ]
];
