<?php

/* gotosocial 0.22.1 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'gotosocial', 'version' => '0.22.1'],
    'seq' => 50,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","actor":"https://remote.example/users/alice","id":"https://remote.example/01KZQE066CVBP2PBHMFPFJWH2X","object":{"actor":"https://remote.example/users/alice","id":"https://remote.example/users/alice/follow/01AW805HP4P8D298Q4KS251TEA","object":"https://local.example/club/test","to":"https://local.example/club/test","type":"Follow"},"to":"https://local.example/club/test","type":"Undo"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_removed' => true
    ]
];
