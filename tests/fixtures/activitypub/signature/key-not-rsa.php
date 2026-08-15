<?php

/* 库里存的是一把 Ed25519 公钥，而 rsa-sha256 / rsa-sha512 / hs2019 三个入口全都落到 RSA 验签。
 * 不查密钥类型就等于把任意密钥交给 RSA 路径，由 openssl 按它自己的规则解释。
 * 这条不是终局的 403：本站手上这把公钥用不了，对端可能刚轮换过，而 actor 刷新还在冷却里 —— 那就是「现在验不了，等会儿再投」。 */

return [
    'source' => ['software' => 'generic', 'version' => 'cavage-12'],
    'seq' => 90,
    'key' => 'ed25519',
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/users/eddie/follow/1","type":"Follow","actor":"https://remote.example/users/eddie","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 503,
        'follower_created' => false,
        'delivery_enqueued' => false
    ]
];
