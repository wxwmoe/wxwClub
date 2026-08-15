<?php

/* rsa-sha512：算法名和真正用的摘要必须一起换，签名串本身跟 rsa-sha256 的形状完全一样。 */

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 20,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['algorithm' => 'rsa-sha512', 'hash' => OPENSSL_ALGO_SHA512],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/carol/follow/1","type":"Follow","actor":"https://remote.example/users/carol","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_created' => true,
        'delivery_enqueued' => true
    ]
];
