<?php

/* pleroma 2.10.2 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'pleroma', 'version' => '2.10.2'],
    'seq' => 21,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://remote.example/schemas/litepub-0.1.jsonld",{"@language":"und"}],"actor":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public"],"context":"https://remote.example/contexts/0cf2ce4d-2112-46d7-865c-2a473ea2cb18","directMessage":false,"id":"https://remote.example/activities/508b2aa5-994d-4bff-b283-6d45372e3751","object":{"actor":"https://remote.example/users/alice","attachment":[],"attributedTo":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public"],"content":"<span class=\"h-card\"><a class=\"u-url mention\" data-user=\"B9FTmTQ84caUYEYZMG\" href=\"https://local.example/club/test\" rel=\"ugc\">@<span>test</span></a></span> test","context":"https://remote.example/contexts/0cf2ce4d-2112-46d7-865c-2a473ea2cb18","conversation":"https://remote.example/contexts/0cf2ce4d-2112-46d7-865c-2a473ea2cb18","id":"https://remote.example/objects/77f93659-1b7f-42bf-909f-4c18500a01dd","published":"2026-08-11T02:29:02.840799Z","replies":{"id":"https://remote.example/objects/77f93659-1b7f-42bf-909f-4c18500a01dd/replies","totalItems":0,"type":"OrderedCollection"},"sensitive":null,"source":{"content":"@test@local.example test","mediaType":"text/plain"},"summary":"CW TEST","tag":[{"href":"https://local.example/club/test","name":"@test@local.example","type":"Mention"}],"to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Note"},"published":"2026-08-11T02:29:02.840697Z","to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Create"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Create',
        'announce_created' => true,
        'delivery_enqueued' => true
    ]
];
