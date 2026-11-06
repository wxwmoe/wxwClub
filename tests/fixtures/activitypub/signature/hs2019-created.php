<?php

return [
    'source' => ['software' => 'gotosocial', 'version' => '0.22.1'],
    'seq' => 10,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['algorithm' => 'hs2019', 'hash' => OPENSSL_ALGO_SHA256, 'headers' => ['(request-target)', 'host', '(created)', 'digest']],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/gts/follow/01G3H09WVCE6NHDATGHVJ40BXM","type":"Follow","actor":"https://remote.example/users/gts","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_created' => true,
        'delivery_enqueued' => true
    ]
];
