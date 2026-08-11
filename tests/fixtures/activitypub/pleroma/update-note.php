<?php

/* pleroma 2.10.2 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'pleroma', 'version' => '2.10.2'],
    'seq' => 30,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://remote.example/schemas/litepub-0.1.jsonld",{"@language":"und"}],"actor":"https://remote.example/users/alice","bto":[],"cc":["https://www.w3.org/ns/activitystreams#Public"],"id":"https://remote.example/activities/f36d6a79-f79a-4bee-ae0c-2a82d5be331b","object":{"actor":"https://remote.example/users/alice","attachment":[],"attributedTo":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public"],"content":"<span class=\"h-card\"><a class=\"u-url mention\" data-user=\"B9FTmTQ84caUYEYZMG\" href=\"https://local.example/club/test\" rel=\"ugc\">@<span>test</span></a></span> test2","context":"https://remote.example/contexts/f81caacb-6256-466a-90cd-4c31f41f62a7","conversation":"https://remote.example/contexts/f81caacb-6256-466a-90cd-4c31f41f62a7","formerRepresentations":{"orderedItems":[{"actor":"https://remote.example/users/alice","attachment":[],"attributedTo":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public"],"content":"<span class=\"h-card\"><a class=\"u-url mention\" data-user=\"B9FTmTQ84caUYEYZMG\" href=\"https://local.example/club/test\" rel=\"ugc\">@<span>test</span></a></span> test","context":"https://remote.example/contexts/f81caacb-6256-466a-90cd-4c31f41f62a7","conversation":"https://remote.example/contexts/f81caacb-6256-466a-90cd-4c31f41f62a7","published":"2026-08-11T02:17:57.582854Z","source":{"content":"@test@local.example test","mediaType":"text/plain"},"summary":"","tag":[{"href":"https://local.example/club/test","name":"@test@local.example","type":"Mention"}],"to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Note"}],"totalItems":1,"type":"OrderedCollection"},"id":"https://remote.example/objects/9f774c80-861c-4afc-9e80-223b39b6f10d","published":"2026-08-11T02:17:57.582854Z","replies":{"id":"https://remote.example/objects/9f774c80-861c-4afc-9e80-223b39b6f10d/replies","totalItems":0,"type":"OrderedCollection"},"source":{"content":"@test@local.example test2","mediaType":"text/plain"},"summary":"","tag":[{"href":"https://local.example/club/test","name":"@test@local.example","type":"Mention"}],"to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Note","updated":"2026-08-11T02:18:30.096062Z"},"to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Update"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'content_updated' => true,
        'relayed' => false,
        'delivery_enqueued' => false
    ]
];
