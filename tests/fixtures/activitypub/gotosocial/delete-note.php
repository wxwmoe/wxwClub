<?php

/* gotosocial 0.22.1 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'gotosocial', 'version' => '0.22.1'],
    'seq' => 40,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","actor":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test"],"id":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K/activity#Delete","object":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K","published":"2026-08-11T11:05:29+08:00","to":"https://remote.example/users/alice/followers","type":"Delete"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Delete',
        'announce_revoked' => true,
        'delivery_enqueued' => true,
        'relayed' => false
    ]
];
