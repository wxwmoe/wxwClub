<?php

/* mastodon 4.3.22 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'mastodon', 'version' => '4.3.22'],
    'seq' => 40,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams",{"ostatus":"http://ostatus.org#","atomUri":"ostatus:atomUri"},"https://w3id.org/security/v1"],"id":"https://remote.example/users/alice/statuses/117074505985336997#delete","type":"Delete","actor":"https://remote.example/users/alice","to":["https://www.w3.org/ns/activitystreams#Public"],"object":{"id":"https://remote.example/users/alice/statuses/117074505985336997","type":"Tombstone","atomUri":"https://remote.example/users/alice/statuses/117074505985336997"},"signature":{"type":"RsaSignature2017","creator":"https://remote.example/users/alice#main-key","created":"2026-08-11T02:26:51Z","signatureValue":"mQwR31I29a1Bv3ezjsvUenTXlFxoz03PBz83hDsUN06+KFc0HMEWZEkV83JSy1KLlJ9iVG9taTZC4EoxE9FP7d3GuXCUTQS9F/JGxW9JgOk35kYnzMS6BLa7cyz9aEUKKUW6fqHaNcebx6hmt0+1HtDOLS9aiwRwygoiCg6+DTUZ0Nh62rqDY/TApNiyVQ+AVK7T6lfPYCV/dRFa7tPHYknrnXjoxyKBdPM52+j0sKgX+oj3WkRxGVBPz8WgBpzUxYst3wDhXsBU3poasz6c6Yy3s08JNoFQVbyeIxVi+v7+LFziuLgdbCTfqrqY3n3BHyU19MWiLOGnTYZuuhVvRw=="}}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Delete',
        'announce_revoked' => true,
        'delivery_enqueued' => true,
        'relayed' => true
    ]
];
