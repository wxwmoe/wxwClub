<?php

/* 同一个头里两个 keyId。后一个覆盖前一个的话，对端就能让日志看到一个身份、让验签用另一个身份。 */

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 60,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['params' => ',keyId="https://remote.example/users/alice#main-key"'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/twice/follow/1","type":"Follow","actor":"https://remote.example/users/twice","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 403,
        'follower_created' => false,
        'delivery_enqueued' => false
    ]
];
