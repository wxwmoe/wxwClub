<?php

return [
    'source' => ['software' => 'gotosocial', 'version' => '0.22.1'],
    'seq' => 40,
    'request' => [
        'method' => 'POST',
        'path' => '/inbox',
        'headers' => ['Content-Type' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"'],
        'body' => <<<'JSON'
{"@context":"https://www.w3.org/ns/activitystreams","actor":"https://remote.example/users/alice","cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test"],"id":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K/activity#Delete","object":"https://remote.example/users/alice/statuses/01KZQCGH2AYV0D8FHG4J00B52K","published":"2026-08-11T11:05:29+08:00","to":"https://remote.example/users/alice/followers","type":"Delete"}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Delete',
        'announce_revoked' => true,
        'delivery_enqueued' => true,
        'relayed' => false
    ]
];
