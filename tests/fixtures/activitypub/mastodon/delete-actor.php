<?php

/* mastodon 4.6.4 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'mastodon', 'version' => '4.6.4'],
    'seq' => 60,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://w3id.org/security/v1"],"id":"https://remote.example/users/alice#delete","type":"Delete","actor":"https://remote.example/users/alice","to":["https://www.w3.org/ns/activitystreams#Public"],"object":"https://remote.example/users/alice","signature":{"type":"RsaSignature2017","creator":"https://remote.example/users/alice#main-key","created":"2026-08-11T02:33:33Z","signatureValue":"kf/kefeG8wHZpPNrGgf+uc25A5Gb0lBReyNaPcG6C8mjKzv4cGnerw/DJQpv8rp52VN8okN/OA1C5OM0tXOvtF0G/o6vUMkjGxaXlcr0N+4vajKb5G13up4zmBFsYcZcb4w1tb8OUqnubm8XGnB94ZhnNq1ofLpCtvc1avheWPZtHVnvFAlhaXnL8QqgRvISXDphyD0uoIyhz2r4GMM3iZu1uPuJkbdpUmUPytvGn5bADjPAWRNibC16nGdVH1gNfam7yLCYDet3FkA2uOBS0FXc+pcBEWy6VVd2epUIK6Y1UNZHx1hT/NCJeudi9ev6OLxhDLN6mMaHLoMAy+MtSw=="}}
JSON
    ],
    'expect' => [
        'status' => 202,
        'actor_deleted' => true
    ]
];
