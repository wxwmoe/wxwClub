<?php

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 90,
    'key' => 'ed25519',
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/eddie/follow/1","type":"Follow","actor":"https://remote.example/users/eddie","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 503,
        'follower_created' => false,
        'delivery_enqueued' => false
    ]
];
