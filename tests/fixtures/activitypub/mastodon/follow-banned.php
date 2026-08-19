<?php

/* mastodon 4.3.22 发来的真实 Follow 报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * 变的只有发送者：ban 那一段让 t_ap_seed 先把它封掉，验的是「封禁在验签之前挡住活动，但提醒私信照发」这条路。 */

return [
    'source' => ['software' => 'mastodon', 'version' => '4.3.22'],
    'seq' => 60,
    'ban' => ['type' => 'actor', 'target' => 'https://remote.example/users/mallory'],
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","id":"https://remote.example/8f2c1d64-4a77-4f0e-9c53-1b0d2a6f9e11","type":"Follow","actor":"https://remote.example/users/mallory","object":"https://local.example/club/test"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_created' => false,
        // 提醒私信就是这次入队的东西：被封的人多半不知道自己被封了
        'delivery_enqueued' => true
    ]
];
