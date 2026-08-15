<?php

/* 签名和 Digest 头彼此自洽，只有正文在路上被换掉了。签名验得过，正文却不是被签的那一份 —— Digest 是这一段唯一的把关。 */

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 80,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['body' => '{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/tamper/follow/1","type":"Follow","actor":"https://remote.example/users/tamper","object":"https://local.example/club/other"}'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/tamper/follow/1","type":"Follow","actor":"https://remote.example/users/tamper","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 403,
        'follower_created' => false,
        'delivery_enqueued' => false
    ]
];
