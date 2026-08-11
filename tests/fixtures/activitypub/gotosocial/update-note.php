<?php

/* gotosocial 0.22.1 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'gotosocial', 'version' => '0.22.1'],
    'seq' => 30,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'],
        'body' => <<<'JSON'
{"@context":["https://gotosocial.org/ns","https://www.w3.org/ns/activitystreams"],"actor":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test"],"id":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K/activity#Update","object":{"attributedTo":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test"],"content":"\u003cp\u003e\u003cspan class=\"h-card\"\u003e\u003ca href=\"https://local.example/club/test\" class=\"u-url mention\" rel=\"nofollow noreferrer noopener\" target=\"_blank\"\u003e@\u003cspan\u003etest\u003c/span\u003e\u003c/a\u003e\u003c/span\u003e test2\u003c/p\u003e","contentMap":{"en":"\u003cp\u003e\u003cspan class=\"h-card\"\u003e\u003ca href=\"https://local.example/club/test\" class=\"u-url mention\" rel=\"nofollow noreferrer noopener\" target=\"_blank\"\u003e@\u003cspan\u003etest\u003c/span\u003e\u003c/a\u003e\u003c/span\u003e test2\u003c/p\u003e"},"id":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K","interactionPolicy":{"canAnnounce":{"automaticApproval":["https://www.w3.org/ns/activitystreams#Public"]},"canLike":{"automaticApproval":["https://www.w3.org/ns/activitystreams#Public"]},"canQuote":{"automaticApproval":["https://remote.example/users/alice"]},"canReply":{"automaticApproval":["https://www.w3.org/ns/activitystreams#Public"]}},"published":"2026-08-11T11:05:29+08:00","replies":{"first":{"id":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K/replies?page=true","next":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K/replies?page=true\u0026only_other_accounts=false","partOf":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K/replies","type":"CollectionPage"},"id":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K/replies","type":"Collection"},"tag":{"href":"https://local.example/club/test","name":"@test@local.example","type":"Mention"},"to":"https://remote.example/users/alice/followers","type":"Note","updated":"2026-08-11T11:05:57+08:00","url":"https://remote.example/@alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K"},"published":"2026-08-11T11:05:29+08:00","to":"https://remote.example/users/alice/followers","type":"Update"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'content_updated' => true,
        'relayed' => false,
        'delivery_enqueued' => false
    ]
];
