<?php

/* GoToSocial 的签名形状：algorithm 写 hs2019，时效靠 (created) 而不是 Date 头。
 * hs2019 在草案里本该按 keyId 的元数据挑摘要算法，Fediverse 上它就是 RSA + SHA-256 的别名，认不出来的话整家 GoToSocial 都投不进来。 */

return [
    'source' => ['software' => 'gotosocial', 'version' => '0.22.1'],
    'seq' => 10,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'sign' => ['algorithm' => 'hs2019', 'hash' => OPENSSL_ALGO_SHA256, 'headers' => ['(request-target)', 'host', '(created)', 'digest']],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/gts/follow/01G3H09WVCE6NHDATGHVJ40BXM","type":"Follow","actor":"https://remote.example/users/gts","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_created' => true,
        'delivery_enqueued' => true
    ]
];
