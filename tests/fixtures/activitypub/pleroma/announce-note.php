<?php

/* pleroma 2.10.2 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'pleroma', 'version' => '2.10.2'],
    'seq' => 20,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://remote.example/schemas/litepub-0.1.jsonld",{"@language":"und"}],"actor":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public"],"context":"https://remote.example/contexts/f81caacb-6256-466a-90cd-4c31f41f62a7","directMessage":false,"id":"https://remote.example/activities/03a4e703-8c74-46ac-873e-99e2954bc966","object":{"actor":"https://remote.example/users/alice","attachment":[],"attributedTo":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public"],"content":"<span class=\"h-card\"><a class=\"u-url mention\" data-user=\"B9FTmTQ84caUYEYZMG\" href=\"https://local.example/club/test\" rel=\"ugc\">@<span>test</span></a></span> test","context":"https://remote.example/contexts/f81caacb-6256-466a-90cd-4c31f41f62a7","conversation":"https://remote.example/contexts/f81caacb-6256-466a-90cd-4c31f41f62a7","id":"https://remote.example/objects/9f774c80-861c-4afc-9e80-223b39b6f10d","published":"2026-08-11T02:17:57.582854Z","replies":{"id":"https://remote.example/objects/9f774c80-861c-4afc-9e80-223b39b6f10d/replies","totalItems":0,"type":"OrderedCollection"},"sensitive":null,"source":{"content":"@test@local.example test","mediaType":"text/plain"},"summary":"","tag":[{"href":"https://local.example/club/test","name":"@test@local.example","type":"Mention"}],"to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Note"},"published":"2026-08-11T02:17:57.582752Z","to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Create"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Create',
        'announce_created' => true,
        'delivery_enqueued' => true
    ]
];
