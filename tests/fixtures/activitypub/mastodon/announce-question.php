<?php

/* mastodon 4.3.22 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'mastodon', 'version' => '4.3.22'],
    'seq' => 25,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams",{"ostatus":"http://ostatus.org#","atomUri":"ostatus:atomUri","inReplyToAtomUri":"ostatus:inReplyToAtomUri","conversation":"ostatus:conversation","sensitive":"as:sensitive","toot":"http://joinmastodon.org/ns#","votersCount":"toot:votersCount"},"https://w3id.org/security/v1"],"id":"https://remote.example/users/alice/statuses/117074527616629378/activity","type":"Create","actor":"https://remote.example/users/alice","published":"2026-08-11T02:32:01Z","to":["https://remote.example/users/alice/followers"],"cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test","https://local.example/club/test/followers"],"object":{"id":"https://remote.example/users/alice/statuses/117074527616629378","type":"Question","summary":null,"inReplyTo":null,"published":"2026-08-11T02:32:01Z","url":"https://remote.example/@alice/117074527616629378","attributedTo":"https://remote.example/users/alice","to":["https://remote.example/users/alice/followers"],"cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test","https://local.example/club/test/followers"],"sensitive":false,"atomUri":"https://remote.example/users/alice/statuses/117074527616629378","inReplyToAtomUri":null,"conversation":"tag:remote.example,2026-08-11:objectId=104107142:objectType=Conversation","content":"\u003cp\u003e\u003cspan class=\"h-card\" translate=\"no\"\u003e\u003ca href=\"https://local.example/club/test\" class=\"u-url mention\"\u003e@\u003cspan\u003etest\u003c/span\u003e\u003c/a\u003e\u003c/span\u003e test\u003c/p\u003e","contentMap":{"zh":"\u003cp\u003e\u003cspan class=\"h-card\" translate=\"no\"\u003e\u003ca href=\"https://local.example/club/test\" class=\"u-url mention\"\u003e@\u003cspan\u003etest\u003c/span\u003e\u003c/a\u003e\u003c/span\u003e test\u003c/p\u003e"},"endTime":"2026-08-12T02:32:01Z","votersCount":0,"attachment":[],"tag":[{"type":"Mention","href":"https://local.example/club/test","name":"@test@local.example"}],"replies":{"id":"https://remote.example/users/alice/statuses/117074527616629378/replies","type":"Collection","first":{"type":"CollectionPage","next":"https://remote.example/users/alice/statuses/117074527616629378/replies?only_other_accounts=true\u0026page=true","partOf":"https://remote.example/users/alice/statuses/117074527616629378/replies","items":[]}},"likes":{"id":"https://remote.example/users/alice/statuses/117074527616629378/likes","type":"Collection","totalItems":0},"shares":{"id":"https://remote.example/users/alice/statuses/117074527616629378/shares","type":"Collection","totalItems":0},"oneOf":[{"type":"Note","name":"A","replies":{"type":"Collection","totalItems":0}},{"type":"Note","name":"B","replies":{"type":"Collection","totalItems":0}},{"type":"Note","name":"C","replies":{"type":"Collection","totalItems":0}},{"type":"Note","name":"D","replies":{"type":"Collection","totalItems":0}}]},"signature":{"type":"RsaSignature2017","creator":"https://remote.example/users/alice#main-key","created":"2026-08-11T02:32:01Z","signatureValue":"BjjcQB/N7zJrBoe4M4qRVOMUlPzeslwNd3RUPM9JTdZtVLliLnsov1UWj6ukZVpskSkicz8cTsjq6eaWTePYCiZrQndAusWTjLMlNPpnkQODq6zj6vEIwM8AU5wq5ux06ih3seFBmAB3bxf+yPd9eltF7ncb3GsBUsyd8oG1WVOnze8o3u2v+T34GhS8OzSGDl7OL+JKUdjOvdPeyDtrl/efR/sDStHIFSLB73BzCJgE1Ye46VYJpWWc6i8n8S9WosS+okLTp+Uic8A1OzU7SFkvh7O4EbAkRSn4ZE45bVDcrcBVk7o94LyWSMJDETwxz0ZOBI0oQXul5o6+r3OQEA=="}}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Create',
        'announce_created' => true,
        'delivery_enqueued' => true
    ]
];
