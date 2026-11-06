<?php

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 30,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['algorithm' => 'hs2019', 'headers' => ['(request-target)', 'host', '(created)', 'digest'], 'created' => time() - 1000],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/stale/follow/1","type":"Follow","actor":"https://remote.example/users/stale","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 403,
        'follower_created' => false,
        'delivery_enqueued' => false
    ]
];
