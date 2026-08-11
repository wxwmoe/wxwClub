<?php

/* pleroma 2.10.2 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'pleroma', 'version' => '2.10.2'],
    'seq' => 23,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://remote.example/schemas/litepub-0.1.jsonld",{"@language":"zh-CN"}],"actor":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public"],"context":"https://remote.example/contexts/a15e8bf4-226b-4fc0-bf82-7130ecb8cb6b","directMessage":false,"id":"https://remote.example/activities/92514e44-7c57-4b51-965d-96ecd0f52217","object":{"actor":"https://remote.example/users/alice","attachment":[],"attributedTo":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public"],"content":"<p><span class=\"h-card\"><a class=\"u-url mention\" data-user=\"B9FTmTQ84caUYEYZMG\" href=\"https://local.example/club/test\" rel=\"ugc\">@<span>test</span></a></span> <strong>test</strong> test</p>","contentMap":{"zh-CN":"<p><span class=\"h-card\"><a class=\"u-url mention\" data-user=\"B9FTmTQ84caUYEYZMG\" href=\"https://local.example/club/test\" rel=\"ugc\">@<span>test</span></a></span> <strong>test</strong> test</p>"},"context":"https://remote.example/contexts/a15e8bf4-226b-4fc0-bf82-7130ecb8cb6b","conversation":"https://remote.example/contexts/a15e8bf4-226b-4fc0-bf82-7130ecb8cb6b","id":"https://remote.example/objects/8a90b74a-c8f5-4350-b46c-1c62b9cd1397","published":"2026-08-11T02:55:58.754156Z","replies":{"id":"https://remote.example/objects/8a90b74a-c8f5-4350-b46c-1c62b9cd1397/replies","totalItems":0,"type":"OrderedCollection"},"sensitive":false,"source":{"content":"@test@local.example **test** test","mediaType":"text/markdown"},"summary":"","tag":[{"href":"https://local.example/club/test","name":"@test@local.example","type":"Mention"}],"to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Note"},"published":"2026-08-11T02:55:58.754071Z","to":["https://remote.example/users/alice/followers","https://local.example/club/test"],"type":"Create"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Create',
        'announce_created' => true,
        'delivery_enqueued' => true
    ]
];
