<?php

return [
    'source' => ['software' => 'gotosocial', 'version' => '0.22.1'],
    'seq' => 10,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","actor":"https://remote.example/users/alice","id":"https://remote.example/users/alice/follow/01G3H09WVCE6NHDATGHVJ40BXM","object":"https://local.example/club/test","to":"https://local.example/club/test","type":"Follow"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_created' => true,
        'delivery_enqueued' => true
    ]
];
