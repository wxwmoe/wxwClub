<?php

return [
    'source' => ['software' => 'pleroma', 'version' => '2.10.2'],
    'seq' => 10,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://remote.example/schemas/litepub-0.1.jsonld",{"@language":"und"}],"actor":"https://remote.example/users/alice","bcc":[],"bto":[],"cc":[],"id":"https://remote.example/activities/1f026d6e-74cb-498f-a176-93783ac11648","object":"https://local.example/club/test","state":"pending","to":["https://local.example/club/test"],"type":"Follow"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'follower_created' => true,
        'delivery_enqueued' => true
    ]
];
