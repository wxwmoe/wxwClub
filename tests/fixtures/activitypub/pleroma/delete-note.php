<?php

/* pleroma 2.10.2 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'pleroma', 'version' => '2.10.2'],
    'seq' => 40,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://remote.example/schemas/litepub-0.1.jsonld",{"@language":"und"}],"actor":"https://remote.example/users/alice","bcc":[],"bto":[],"cc":[],"id":"https://remote.example/activities/63b4ecc6-8830-43c7-a28f-be086bd788dc","object":"https://remote.example/objects/9f774c80-861c-4afc-9e80-223b39b6f10d","to":["https://remote.example/users/alice/followers","https://local.example/club/test","https://www.w3.org/ns/activitystreams#Public"],"type":"Delete"}
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
