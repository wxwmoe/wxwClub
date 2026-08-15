<?php

/* signature= 根本不是 base64。宽松解码会把非法字符悄悄丢掉，剩下的字节照样交给 openssl，失败原因就变成了「签名对不上」。 */

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 70,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['signature' => '!!! not base64 !!!'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/badsig/follow/1","type":"Follow","actor":"https://remote.example/users/badsig","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 403,
        'follower_created' => false,
        'delivery_enqueued' => false
    ]
];
