<?php

/* headers= 里列了一个请求里根本没有的头。拿空值凑出那一行的话，签名串就是本站替对端补齐的，
 * 对端签的和本站验的是两份不同的东西 —— 存在但为空才允许，不存在必须拒。 */

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 50,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['headers' => ['(request-target)', 'host', 'date', 'digest', 'x-forwarded-for']],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/nohead/follow/1","type":"Follow","actor":"https://remote.example/users/nohead","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 403,
        'follower_created' => false,
        'delivery_enqueued' => false
    ]
];
