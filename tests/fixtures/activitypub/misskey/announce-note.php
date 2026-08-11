<?php

/* misskey 2026.3.2 发来的真实报文，域名和用户名已改写成 example 域，其余字节原样保留。
 * signature 块（RsaSignature2017）改写后在密码学上已经失效，这里只用来验「有没有 LD 签名」这道转发闸门。 */

return [
    'source' => ['software' => 'misskey', 'version' => '2026.3.2'],
    'seq' => 20,
    'request' => [
        'method' => 'POST',
        'path' => '/club/test/inbox',
        'headers' => ['Content-Type' => 'application/activity+json'],
        'body' => <<<'JSON'
{"@context":["https://www.w3.org/ns/activitystreams","https://w3id.org/security/v1",{"Key":"sec:Key","manuallyApprovesFollowers":"as:manuallyApprovesFollowers","sensitive":"as:sensitive","Hashtag":"as:Hashtag","quoteUrl":"as:quoteUrl","toot":"http://joinmastodon.org/ns#","Emoji":"toot:Emoji","featured":"toot:featured","discoverable":"toot:discoverable","schema":"http://schema.org#","PropertyValue":"schema:PropertyValue","value":"schema:value","misskey":"https://misskey-hub.net/ns#","_misskey_content":"misskey:_misskey_content","_misskey_quote":"misskey:_misskey_quote","_misskey_reaction":"misskey:_misskey_reaction","_misskey_votes":"misskey:_misskey_votes","_misskey_summary":"misskey:_misskey_summary","_misskey_followedMessage":"misskey:_misskey_followedMessage","_misskey_requireSigninToViewContents":"misskey:_misskey_requireSigninToViewContents","_misskey_makeNotesFollowersOnlyBefore":"misskey:_misskey_makeNotesFollowersOnlyBefore","_misskey_makeNotesHiddenBefore":"misskey:_misskey_makeNotesHiddenBefore","_misskey_license":"misskey:_misskey_license","freeText":{"@id":"misskey:freeText","@type":"schema:text"},"isCat":"misskey:isCat","vcard":"http://www.w3.org/2006/vcard/ns#"}],"id":"https://remote.example/notes/aprmy9nqzl/activity","actor":"https://remote.example/users/alice","type":"Create","published":"2026-08-11T02:58:56.486Z","object":{"id":"https://remote.example/notes/aprmy9nqzl","type":"Note","attributedTo":"https://remote.example/users/alice","content":"<a href=\"https://local.example/club/test\" class=\"u-url mention\">@test@local.example</a> test","published":"2026-08-11T02:58:56.486Z","to":["https://remote.example/users/alice/followers"],"cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test"],"inReplyTo":null,"attachment":[],"sensitive":false,"tag":[{"type":"Mention","href":"https://local.example/club/test","name":"@test@local.example"}]},"to":["https://remote.example/users/alice/followers"],"cc":["https://www.w3.org/ns/activitystreams#Public","https://local.example/club/test"]}
JSON
    ],
    'expect' => [
        'status' => 202,
        'stored' => 'Create',
        'announce_created' => true,
        'delivery_enqueued' => true
    ]
];
