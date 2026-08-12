<?php return [
    'https://www.w3.org/ns/activitystreams',
    'https://w3id.org/security/v1',
    [
        'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
        'toot' => 'http://joinmastodon.org/ns#',
        'featured' => ['@id' => 'toot:featured', '@type' => '@id'],
        'featuredTags' => ['@id' => 'toot:featuredTags', '@type' => '@id'],
        'discoverable' => 'toot:discoverable',
        'devices' => ['@type' => '@id', '@id' => 'toot:devices'],
    ]
];
