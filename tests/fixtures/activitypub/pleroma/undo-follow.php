<?php

/* pleroma 2.10.2 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'pleroma', 'version' => '2.10.2'],
    'seq' => 50,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://remote.example/schemas/litepub-0.1.jsonld",{"@language":"und"}],"actor":"https://remote.example/users/alice","cc":[],"context":"https://remote.example/contexts/1ad966d6-2665-4b84-89c1-16253527aa59","id":"https://remote.example/activities/90dd98c9-6a48-4bb4-aa95-cf2f88915eda","object":{"actor":"https://remote.example/users/alice","bcc":[],"bto":[],"cc":[],"context":"https://remote.example/contexts/1ad966d6-2665-4b84-89c1-16253527aa59","id":"https://remote.example/activities/1f026d6e-74cb-498f-a176-93783ac11648","object":"https://local.example/club/test","published":"2026-08-11T03:34:13.868196Z","state":"cancelled","to":["https://local.example/club/test"],"type":"Follow"},"published":"2026-08-11T03:34:13.868178Z","to":["https://local.example/club/test"],"type":"Undo"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_removed' => true
    ]
];
