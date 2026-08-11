<?php

/* mastodon 4.3.22 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'mastodon', 'version' => '4.3.22'],
    'seq' => 50,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/alice#follows/168591/undo","type":"Undo","actor":"https://remote.example/users/alice","object":{"id":"https://remote.example/2e91cf80-6278-4884-b18b-2faf76207d85","type":"Follow","actor":"https://remote.example/users/alice","object":"https://local.example/club/test"}}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_removed' => true
    ]
];
