<?php

/* mastodon 4.3.22 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'mastodon', 'version' => '4.3.22'],
    'seq' => 20,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams",{"ostatus":"http://ostatus.org#","atomUri":"ostatus:atomUri","inReplyToAtomUri":"ostatus:inReplyToAtomUri","conversation":"ostatus:conversation","sensitive":"as:sensitive","toot":"http://joinmastodon.org/ns#","votersCount":"toot:votersCount"},"https://w3id.org/security/v1"],"id":"https://remote.example/users/alice/statuses/117074505985336997/activity","type":"Create","actor":"https://remote.example/users/alice","published":"2026-08-11T02:26:31Z","to":["https://remote.example/users/alice/followers"],"cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test","https://local.example/club/test/followers"],"object":{"id":"https://remote.example/users/alice/statuses/117074505985336997","type":"Note","summary":null,"inReplyTo":null,"published":"2026-08-11T02:26:31Z","url":"https://remote.example/@alice/117074505985336997","attributedTo":"https://remote.example/users/alice","to":["https://remote.example/users/alice/followers"],"cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test","https://local.example/club/test/followers"],"sensitive":false,"atomUri":"https://remote.example/users/alice/statuses/117074505985336997","inReplyToAtomUri":null,"conversation":"tag:remote.example,2026-08-11:objectId=104107000:objectType=Conversation","content":"\u003cp\u003e\u003cspan class=\"h-card\" translate=\"no\"\u003e\u003ca href=\"https://local.example/club/test\" class=\"u-url mention\"\u003e@\u003cspan\u003etest\u003c/span\u003e\u003c/a\u003e\u003c/span\u003e test\u003c/p\u003e","contentMap":{"zh":"\u003cp\u003e\u003cspan class=\"h-card\" translate=\"no\"\u003e\u003ca href=\"https://local.example/club/test\" class=\"u-url mention\"\u003e@\u003cspan\u003etest\u003c/span\u003e\u003c/a\u003e\u003c/span\u003e test\u003c/p\u003e"},"attachment":[],"tag":[{"type":"Mention","href":"https://local.example/club/test","name":"@test@local.example"}],"replies":{"id":"https://remote.example/users/alice/statuses/117074505985336997/replies","type":"Collection","first":{"type":"CollectionPage","next":"https://remote.example/users/alice/statuses/117074505985336997/replies?only_other_accounts=true\u0026page=true","partOf":"https://remote.example/users/alice/statuses/117074505985336997/replies","items":[]}},"likes":{"id":"https://remote.example/users/alice/statuses/117074505985336997/likes","type":"Collection","totalItems":0},"shares":{"id":"https://remote.example/users/alice/statuses/117074505985336997/shares","type":"Collection","totalItems":0}},"signature":{"type":"RsaSignature2017","creator":"https://remote.example/users/alice#main-key","created":"2026-08-11T02:26:31Z","signatureValue":"iezypvHoFbamj4iPdLtNtrygLV5MKJAJnhrtPobhEm8pAS7repprO/P03GMQYYZxst3es5PZLgIDsW8zLYDIQFBexc+rx323EqG0AA3HQF0hcXPk8hHKRmIdS/UZ+9gzLkxfSe1DOeNI/TrMKs69VIhF7a9RJCZbKuIbWAo0NUBLQFiugJYs2C+gHmLydVcUtN1RPOas/bHjhqWE3Y/lrrhx5ms+HiUR4PzqbmM4hVgoHjfan425L9qwoCMOc76aT5h37fVESDpxQ73SMWfWFodnVT9VaJTmd/wxCmFktDEZXQZXx8LGqIQ9sLD2WZ5SJGItjx3evnd9oX0bFHtRWg=="}}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Create',
        'announce_created' => true,
        'delivery_enqueued' => true
    ]
];
