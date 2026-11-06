<?php

return [
    'source' => ['software' => 'mastodon', 'version' => '4.3.22'],
    'seq' => 60,
    'ban' => ['type' => 'actor', 'target' => 'https://remote.example/users/mallory'],
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/8f2c1d64-4a77-4f0e-9c53-1b0d2a6f9e11","type":"Follow","actor":"https://remote.example/users/mallory","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_created' => false,
        'delivery_enqueued' => true
    ]
];
