<?php

t_group('pure / endpoint normalize');

t_table('Club_Endpoint_Normalize', 'Club_Endpoint_Normalize', [
    ['https://a.example/inbox', 'https://a.example/inbox'],
    ['HTTPS://A.Example/inbox', 'https://a.example/inbox'],
    ['https://a.example:443/inbox', 'https://a.example/inbox'],
    ['http://a.example:80/inbox', 'http://a.example/inbox'],
    ['https://a.example:8443/inbox', 'https://a.example:8443/inbox'],
    ['https://a.example', 'https://a.example/'],
    ['https://a.example/Inbox', 'https://a.example/Inbox'],
    ['https://a.example/inbox?x=0', 'https://a.example/inbox?x=0'],
    ['https://a.example/inbox?', 'https://a.example/inbox?'],
    ['https://[2001:0db8:0000:0000:0000:0000:0000:0001]/inbox', 'https://[2001:db8::1]/inbox'],
    ['https://a.example./inbox', 'https://a.example./inbox'],
    ['https://a_b.example/inbox', 'https://a_b.example/inbox'],
    ['https://a.example/inbox#frag', false],
    ['https://a.example/inbox#', false],
    ['https://user:pw@a.example/inbox', false],
    ['ftp://a.example/inbox', false],
    ['https://a.example:0/inbox', false],
    ['https://a.example:70000/inbox', false],
    ['//a.example/inbox', false],
    ['https://a.example/in box', false],
    ['https://ä.example/inbox', false],
    ['https://-a.example/inbox', false],
    ['', false],
    [null, false],
    [123, false]
]);
$long = 'https://a.example/'.str_repeat('x', 255 - strlen('https://a.example/'));
t_is(Club_Endpoint_Normalize($long), $long, 'Club_Endpoint_Normalize at 255 bytes');
t_is(Club_Endpoint_Normalize($long.'x'), false, 'Club_Endpoint_Normalize over 255 bytes');

t_table('Club_Url_Host', 'Club_Url_Host', [
    ['Remote.Example.', 'remote.example'], ['[2001:0db8::1]', '2001:db8::1'], ['127.0.0.1', '127.0.0.1'],
    ['https://remote.example', false], ['remote.example:8443', false], ['-remote.example', false], ['', false]
]);

t_table('ActivityPub_WebFinger_Resource', 'ActivityPub_WebFinger_Resource', [
    ['acct:test@local.example', 'https://local.example', 'local.example', 'test'],
    ['https://local.example/club/test', 'https://local.example', 'local.example', 'test'],
    ['acct:test@remote.example', 'https://local.example', 'local.example', null],
    ['acct:test', 'https://local.example', 'local.example', false],
    ['http://local.example/club/test', 'https://local.example', 'local.example', false],
    ['https://local.example/club/test?x=1', 'https://local.example', 'local.example', false],
    ['https://local.example/club/test#key', 'https://local.example', 'local.example', false],
    ['https://local.example/@test', 'https://local.example', 'local.example', false],
    ['https://local.example/author/test', 'https://local.example', 'local.example', false]
]);

t_group('pure / ssrf');

t_table('Club_IP_Public', 'Club_IP_Public', [
    ['1.1.1.1', true], ['8.8.8.8', true], ['172.32.0.1', true],
    ['2606:4700:4700::1111', true], ['2001:4860:4860::8888', true],
    ['0.0.0.0', false], ['10.0.0.1', false], ['100.64.0.1', false], ['127.0.0.1', false],
    ['169.254.169.254', false], ['172.16.0.1', false], ['192.0.0.1', false], ['192.0.2.1', false],
    ['192.88.99.1', false], ['192.168.1.1', false], ['198.18.0.1', false], ['198.51.100.1', false],
    ['203.0.113.1', false], ['224.0.0.1', false], ['255.255.255.255', false],
    ['::1', false], ['fe80::1', false], ['fc00::1', false], ['2001:db8::1', false],
    ['2001:20::1', false], ['2002::1', false], ['3fff::1', false], ['64:ff9b::1', false],
    ['::ffff:127.0.0.1', false],
    ['not-an-ip', false], ['', false], [12, false]
]);

t_is(Club_IP_Matches(inet_pton('100.64.0.1'), '100.64.0.0', 10), true, 'Club_IP_Matches within a /10');
t_is(Club_IP_Matches(inet_pton('100.128.0.1'), '100.64.0.0', 10), false, 'Club_IP_Matches outside a /10');
t_is(Club_IP_Matches(inet_pton('::1'), '10.0.0.0', 8), false, 'Club_IP_Matches across address families');

t_is(Club_Url_Public('https://1.1.1.1/inbox'), ['1.1.1.1'], 'Club_Url_Public keeps a public literal');
t_is(Club_Url_Public('http://127.0.0.1/inbox'), false, 'Club_Url_Public blocks loopback');
t_is(Club_Url_Public('https://[::1]/inbox'), false, 'Club_Url_Public blocks IPv6 loopback');
t_is(Club_Url_Public('https://169.254.169.254/latest/meta-data/'), false, 'Club_Url_Public blocks link-local metadata');
t_is(Club_Url_Public('file:///etc/passwd'), false, 'Club_Url_Public blocks non-http schemes');
$unverified = false;
try { ActivityPub_Request('http://127.0.0.1/inbox', '', [], null, []); } catch (InvalidArgumentException $e) { $unverified = true; }
t_ok($unverified, 'federation transport refuses an empty pin list before opening a connection');

t_group('pure / http and json shapes');

t_table('Club_HTTP_Fallback', 'Club_HTTP_Fallback', [
    [false, 403, false, false, true],
    [false, 503, false, false, true],
    [false, 501, false, false, false],
    [false, 401, false, false, false],
    [false, 429, false, false, false],
    [false, 404, false, false, false],
    [false, 0, true, false, true],
    [false, 200, false, false, false],
    [true, 403, false, false, true],
    [true, 503, false, false, false],
    [true, 0, true, true, true],
    [true, 0, true, false, false],
    [true, 202, true, false, false]
]);

if (defined('CURLOPT_CONNECT_TO')) {
    $pin = null;
    $route = Club_HTTP_Route('https://a.example:8443/inbox', ['1.1.1.1', '2606:4700:4700::1111'], '', $pin);
    t_is($route, [CURLOPT_PROXY => '', CURLOPT_RESOLVE => ['a.example:8443:1.1.1.1,[2606:4700:4700::1111]']], 'direct requests pin every vetted address and ignore environment proxies');
    foreach (['http://proxy:3128', 'socks5://proxy:1080'] as $proxy) {
        $pin = 'a.example:8443';
        $route = Club_HTTP_Route('https://b.example/inbox', ['2606:4700:4700::1111'], $proxy, $pin);
        t_is($route[CURLOPT_CONNECT_TO], ['::[2606:4700:4700::1111]:'], 'a proxy tunnel connects to the vetted IPv6 literal');
        t_is([$route[CURLOPT_PROXY], $route[CURLOPT_NOPROXY], $route[CURLOPT_HTTPPROXYTUNNEL]], [$proxy, '', true], 'explicit proxies require a tunnel and override NO_PROXY');
        t_is([$route[CURLOPT_RESOLVE], $pin], [['-a.example:8443'], null], 'switching targets removes the old DNS pin');
    }
    t_table('Club_HTTP_Proxy_Result', 'Club_HTTP_Proxy_Result', [
        [CURLE_COULDNT_RESOLVE_PROXY, '', 0, false, false, 'local-proxy'],
        [CURLE_COULDNT_CONNECT, '', 0, false, false, 'local-proxy'],
        [CURLE_OPERATION_TIMEOUTED, '', 0, true, true, 'failed'],
        [CURLE_RECV_ERROR, '', 407, true, false, 'local-proxy'],
        [CURLE_RECV_ERROR, '', 503, true, false, 'local-proxy'],
        [CURLE_RECV_ERROR, '', 502, true, false, 'next-address'],
        [CURLE_RECV_ERROR, '', 502, true, true, 'failed'],
        [97, 'cannot complete SOCKS5 connection to 1.1.1.1. (5)', 0, true, false, 'next-address'],
        [97, 'cannot complete SOCKS5 connection to 1.1.1.1. (2)', 0, true, false, 'local-proxy'],
        [97, 'failed to receive SOCKS5 initial response', 0, true, false, 'local-proxy'],
        [CURLE_GOT_NOTHING, '', 200, true, true, 'failed']
    ]);
}

t_table('ActivityPub_Sign_Fields', 'ActivityPub_Sign_Fields', [
    ['https://a.example/inbox', ['authority' => 'a.example', 'target' => '/inbox']],
    ['https://a.example:443/inbox', ['authority' => 'a.example', 'target' => '/inbox']],
    ['https://a.example:8443/inbox', ['authority' => 'a.example:8443', 'target' => '/inbox']],
    ['http://a.example:80/x', ['authority' => 'a.example', 'target' => '/x']],
    ['https://a.example', ['authority' => 'a.example', 'target' => '/']],
    ['https://a.example/x?y=1#z', ['authority' => 'a.example', 'target' => '/x?y=1']],
    ['https://u:p@a.example/x', false],
    ['ftp://a.example/x', false],
    ['not-a-url', false]
]);

t_table('Club_Url_Absolute', 'Club_Url_Absolute', [
    ['https://b.example/x', 'https://a.example/y', 'https://b.example/x'],
    ['/x', 'https://a.example/a/b', 'https://a.example/x'],
    ['//b.example/x', 'https://a.example/a/b', 'https://b.example/x'],
    ['x', 'https://a.example/a/b', 'https://a.example/a/x'],
    ['x', 'https://a.example', 'https://a.example/x'],
    ['/x', 'https://a.example:8443/a', 'https://a.example:8443/x'],
    ['/x', 'mailto:someone@a.example', '']
]);

t_is(Club_HTTP_Header(['Location' => 'a'], 'location'), 'a', 'Club_HTTP_Header is case insensitive');
t_is(Club_HTTP_Header(['location' => 'a'], 'Location'), 'a', 'Club_HTTP_Header matches lowercase HTTP/2 headers');
t_is(Club_HTTP_Header([], 'Location'), '', 'Club_HTTP_Header on an empty header set');
t_is(Club_HTTP_Header(null, 'Location'), '', 'Club_HTTP_Header on no headers at all');

t_table('Club_HTTP_Accept_ActivityPub', 'Club_HTTP_Accept_ActivityPub', [
    ['application/activity+json', true],
    ['application/json', true],
    ['APPLICATION/Activity+JSON', true],
    ['application/ld+json; profile="https://www.w3.org/ns/activitystreams"', true],
    ['application/ld+json', false],
    ['application/activity+json, text/html;q=0.9', true],
    ['text/html;q=0.9, application/activity+json', true],
    ['text/html, application/activity+json', false],
    ['application/activity+json;q=0', false],
    ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', false],
    ['*/*', false],
    ['text/html', false],
    ['', false],
    [null, false],
    [['application/activity+json'], false]
]);

t_table('Club_HTTP_Cursor', 'Club_HTTP_Cursor', [
    ['123.45', [123, 45]],
    ['0.0', [0, 0]],
    ['01.02', [1, 2]],
    ['1.2.3', false],
    ['x', false],
    ['', false],
    [123, false],
    [['1'], false]
]);

t_table('ActivityPub_Object_Id', 'ActivityPub_Object_Id', [
    ['https://a.example/s/1', 'https://a.example/s/1'],
    [['id' => 'https://a.example/s/1'], 'https://a.example/s/1'],
    [['id' => ['nested']], ''],
    [[], ''],
    [123, ''],
    [null, '']
]);

t_table('Club_Group_From_Object', 'Club_Group_From_Object', [
    ['https://a.example/club/test', 'test'],
    ['https://a.example/club/test/followers', 'test'],
    [['id' => 'https://a.example/club/test'], 'test'],
    ['https://a.example/users/test', false],
    ['', false]
]);

t_table('Club_Ban_Scope', 'Club_Ban_Scope', [
    [['a'], ['b'], ['a', 'b']],
    [['a'], ['a'], ['a']],
    [[], ['a'], ['a']],
    [null, ['a'], null],
    [['a'], null, null],
    [null, null, null]
]);

t_table('Club_Ban_Target', 'Club_Ban_Target', [
    ['https://a.example/users/alice', ['actor', 'https://a.example/users/alice']],
    ['HTTPS://A.Example:443/users/Alice', ['actor', 'https://a.example/users/Alice']],
    ['a.example', ['host', 'a.example']],
    ['A.Example.', ['host', 'a.example']],
    [' a.example ', ['host', 'a.example']],
    ['alice@a.example', false],
    ['a.example/users/alice', false],
    ['', false],
    ['ftp://a.example/', false]
]);

$t_actor = 'https://social.a.example/users/alice';
t_table('Club_Actor_Handle', 'Club_Actor_Handle', [
    [['webfinger' => 'acct:alice@a.example', 'preferredUsername' => 'alice', 'id' => $t_actor], $t_actor, 'alice@a.example'],
    [['webfinger' => 'alice@a.example', 'id' => $t_actor], $t_actor, 'alice@a.example'],
    [['webfinger' => 'ACCT:alice@a.example', 'id' => $t_actor], $t_actor, 'alice@a.example'],
    [['webfinger' => 'not a handle', 'preferredUsername' => 'alice', 'id' => $t_actor], $t_actor, 'alice@social.a.example'],
    [['webfinger' => ['acct:alice@a.example'], 'preferredUsername' => 'alice', 'id' => $t_actor], $t_actor, 'alice@social.a.example'],
    [['preferredUsername' => 'alice', 'id' => $t_actor], $t_actor, 'alice@social.a.example'],
    [['preferredUsername' => 'alice'], $t_actor, 'alice@social.a.example'],
    [['preferredUsername' => '', 'id' => $t_actor], $t_actor, ''],
    [['id' => $t_actor], $t_actor, ''],
    [['webfinger' => 'acct:alice@例え.jp', 'id' => 'https://例え.jp/users/alice'], 'https://例え.jp/users/alice', ''],
    [['webfinger' => 'acct:'.str_repeat('a', 96).'@a.example', 'id' => $t_actor], $t_actor, ''],
    [['preferredUsername' => 'a/b', 'id' => $t_actor], $t_actor, ''],
    [[], $t_actor, ''],
    [false, $t_actor, '']
]);

t_table('Club_Actor_Aliases', 'Club_Actor_Aliases', [
    [['alsoKnownAs' => ['https://a.example/users/old']], ['https://a.example/users/old']],
    [['alsoKnownAs' => 'https://a.example/users/old'], ['https://a.example/users/old']],
    [['alsoKnownAs' => ['id' => 'https://a.example/users/old']], ['https://a.example/users/old']],
    [['alsoKnownAs' => ['https://c.example/users/x', ['id' => 'https://a.example/users/old']]], ['https://c.example/users/x', 'https://a.example/users/old']],
    [['alsoKnownAs' => [123, '', ['id' => 456]]], []],
    [['alsoKnownAs' => []], []],
    [['alsoKnownAs' => 123], []],
    [[], []],
    [false, []]
]);
t_is(count(Club_Actor_Aliases(['alsoKnownAs' => array_fill(0, 50, 'https://a.example/users/old')])), 10, 'Club_Actor_Aliases caps a long alias list');

t_table('Club_I18n_Match', 'Club_I18n_Match', [
    ['zh', 'zh-CN'], ['zh-Hant', 'zh-TW'], ['ZH_TW', 'zh-TW'], ['zh-mo', 'zh-HK'],
    ['yue', 'zh-HK'], ['en-GB', 'en'], ['ja-JP', 'ja'], ['fr', false], ['', false]
]);

t_table('Club_Log_Slug', 'Club_Log_Slug', [['a/b', 'aⳆb'], ['a\\b', 'aⳆb'], ["a\tb", 'a_b'], ['a*b?[c]', 'abc'], ["a\x00b", 'ab']]);

t_group('pure / signature header');

function t_sig($params = []) {
    return array_merge(['keyid' => '', 'algorithm' => '', 'headers' => '', 'signature' => '', 'created' => '', 'expires' => ''], $params);
}

t_table('ActivityPub_Signature_Parse', 'ActivityPub_Signature_Parse', [
    ['keyId="https://a.example/users/alice#main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="AAAA"',
        t_sig(['keyid' => 'https://a.example/users/alice#main-key', 'algorithm' => 'rsa-sha256', 'headers' => '(request-target) host date digest', 'signature' => 'AAAA'])],
    ['signature="AAAA",headers="(request-target) date",keyId="k"', t_sig(['keyid' => 'k', 'headers' => '(request-target) date', 'signature' => 'AAAA'])],
    ['keyId="a\"b\\\\c",signature="AAAA"', t_sig(['keyid' => 'a"b\\c', 'signature' => 'AAAA'])],
    ['keyId="k",signature="AAAA",headers="(created) (expires)",created=1402170695,expires=1402170995',
        t_sig(['keyid' => 'k', 'headers' => '(created) (expires)', 'signature' => 'AAAA', 'created' => '1402170695', 'expires' => '1402170995'])],
    ['keyId="k",created="1402170695"', t_sig(['keyid' => 'k', 'created' => '1402170695'])],
    ['KEYID="k",Algorithm="RSA-SHA256"', t_sig(['keyid' => 'k', 'algorithm' => 'RSA-SHA256'])],
    ['keyId="k",opaque="whatever",signature="AAAA"', t_sig(['keyid' => 'k', 'signature' => 'AAAA'])],
    ['keyId = "k" , signature = "AAAA"', t_sig(['keyid' => 'k', 'signature' => 'AAAA'])],
    ['keyId="",signature="AAAA"', t_sig(['signature' => 'AAAA'])],
    ['keyId="a",keyId="b"', false],
    ['keyId="a",KEYID="b"', false],
    ['keyId="a', false],
    ['keyId="a\"', false],
    ['keyId="a" algorithm="rsa-sha256"', false],
    ['keyId="a";algorithm="rsa-sha256"', false],
    ['keyId="a",', false],
    ['keyId="a"junk', false],
    [',keyId="a"', false],
    ['="a"', false],
    ['keyId', false],
    ['keyId=', false],
    ['', false],
    [null, false],
    [123, false]
]);
t_is(ActivityPub_Signature_Parse('keyId="'.str_repeat('k', 4000).'"'), t_sig(['keyid' => str_repeat('k', 4000)]), 'ActivityPub_Signature_Parse under the length cap');
t_is(ActivityPub_Signature_Parse('keyId="'.str_repeat('k', 4100).'"'), false, 'ActivityPub_Signature_Parse over the length cap');
ActivityPub_Signature_Parse('keyId="a",keyId="b"', $t_error);
t_is($t_error, 'duplicate parameter: keyid', 'ActivityPub_Signature_Parse reports a stable reason');

t_group('pure / date and digest');

t_is(ActivityPub_Verify_Date(gmdate('D, d M Y H:i:s T')), true, 'ActivityPub_Verify_Date accepts now');
t_is(ActivityPub_Verify_Date(gmdate('D, d M Y H:i:s T', time() - 299)), true, 'ActivityPub_Verify_Date accepts the edge of the window');
t_is(ActivityPub_Verify_Date(gmdate('D, d M Y H:i:s T', time() - 400)), false, 'ActivityPub_Verify_Date rejects a stale date');
t_is(ActivityPub_Verify_Date(gmdate('D, d M Y H:i:s T', time() + 400)), false, 'ActivityPub_Verify_Date rejects a future date');
t_is(ActivityPub_Verify_Date((string)time()), true, 'ActivityPub_Verify_Date accepts a unix timestamp');
t_is(ActivityPub_Verify_Date((string)(time() - 1000)), false, 'ActivityPub_Verify_Date rejects a stale unix timestamp');
t_is(ActivityPub_Verify_Date(''), false, 'ActivityPub_Verify_Date rejects an empty date');
t_is(ActivityPub_Verify_Date('garbage'), false, 'ActivityPub_Verify_Date rejects an unparsable date');

$body = '{"type":"Follow"}';
$t_sha256 = hash('sha256', $body, true); $t_sha512 = hash('sha512', $body, true);
t_table('ActivityPub_Digest_Parse', 'ActivityPub_Digest_Parse', [
    ['SHA-256='.base64_encode($t_sha256), ['sha256' => $t_sha256]],
    ['sha-256='.base64_encode($t_sha256), ['sha256' => $t_sha256]],
    ['SHA256='.base64_encode($t_sha256), []],
    ['SHA-2-56='.base64_encode($t_sha256), []],
    ['SHA-512='.base64_encode($t_sha512), ['sha512' => $t_sha512]],
    ['SHA-256='.base64_encode($t_sha256).', SHA-512='.base64_encode($t_sha512), ['sha256' => $t_sha256, 'sha512' => $t_sha512]],
    ['SHA-256='.base64_encode($t_sha256).',SHA-256='.base64_encode($t_sha256), ['sha256' => $t_sha256]],
    ['SHA-256='.base64_encode($t_sha256).',SHA-256='.base64_encode(hash('sha256', $body.' ', true)), false],
    ['unixsum=1234', []],
    ['unixsum=1234,SHA-256='.base64_encode($t_sha256), ['sha256' => $t_sha256]],
    ['SHA-256='.base64_encode(hash('md5', $body, true)), false],
    ['SHA-512='.base64_encode($t_sha256), false],
    ['SHA-256='.base64_encode($t_sha256).' junk', false],
    ['SHA-256=not base64', false],
    ['SHA-256=', false],
    ['garbage', false],
    ['', false],
    [null, false],
    [123, false]
]);

$_SERVER['HTTP_DIGEST'] = 'SHA-256='.base64_encode($t_sha256);
t_is(ActivityPub_Verify_Digest($body), true, 'ActivityPub_Verify_Digest accepts a matching sha-256');
t_is(ActivityPub_Verify_Digest($body.' '), false, 'ActivityPub_Verify_Digest rejects a modified body');
$_SERVER['HTTP_DIGEST'] = 'SHA-512='.base64_encode($t_sha512);
t_is(ActivityPub_Verify_Digest($body), true, 'ActivityPub_Verify_Digest accepts sha-512');
$_SERVER['HTTP_DIGEST'] = 'SHA-256='.base64_encode($t_sha256).',SHA-512='.base64_encode($t_sha512);
t_is(ActivityPub_Verify_Digest($body), true, 'ActivityPub_Verify_Digest accepts both digests at once');
$_SERVER['HTTP_DIGEST'] = 'SHA-256='.base64_encode($t_sha256).',SHA-512='.base64_encode(hash('sha512', $body.' ', true));
t_is(ActivityPub_Verify_Digest($body), false, 'ActivityPub_Verify_Digest checks every digest it understands');
$_SERVER['HTTP_DIGEST'] = 'MD5='.base64_encode(hash('md5', $body, true));
t_is(ActivityPub_Verify_Digest($body), false, 'ActivityPub_Verify_Digest rejects an unsupported algorithm');
$_SERVER['HTTP_DIGEST'] = 'garbage';
t_is(ActivityPub_Verify_Digest($body), false, 'ActivityPub_Verify_Digest rejects a malformed header');
unset($_SERVER['HTTP_DIGEST']);

t_group('pure / doh answers');

t_table('Club_Resolver_Answer', 'Club_Resolver_Answer', [
    [null, 'A', false],
    [['nostatus' => 1], 'A', false],
    [['Status' => 2], 'A', null],
    [['Status' => 5], 'A', false],
    [['Status' => 3], 'A', []],
    [['Status' => 3, 'Answer' => [['type' => 1, 'data' => '1.2.3.4']]], 'A', []],
    [['Status' => 0, 'TC' => true, 'Answer' => [['type' => 1, 'data' => '1.2.3.4']]], 'A', false],
    [['Status' => 0, 'Answer' => 'not-a-list'], 'A', false],
    [['Status' => 0], 'A', []],
    [['Status' => 0, 'Answer' => [['type' => 5, 'data' => 'x.example'], ['type' => 1, 'data' => '1.2.3.4']]], 'A', ['1.2.3.4']],
    [['Status' => 0, 'Answer' => [['type' => '1', 'data' => '1.2.3.4']]], 'A', ['1.2.3.4']],
    [['Status' => 0, 'Answer' => [['type' => 1, 'data' => 'garbage']]], 'A', false],
    [['Status' => 0, 'Answer' => ['scalar']], 'A', false],
    [['Status' => 0, 'Answer' => [['type' => 5, 'data' => 'x.example']]], 'A', []],
    [['Status' => 0, 'Answer' => [['type' => 28, 'data' => '2001:db8::1']]], 'AAAA', ['2001:db8::1']],
    [['Status' => 0, 'Answer' => [['type' => 1, 'data' => '1.2.3.4']]], 'AAAA', []]
]);

t_group('decide / handle confirm');

t_table('Club_Actor_Confirm_Decide', 'Club_Actor_Confirm_Decide', [
    [true, false, 'alice@a.example', 'alice@a.example', 999000, true],
    [false, true, 'bob@a.example', 'alice@a.example', 0, false],
    [null, true, 'alice@a.example', 'alice@a.example', 0, true],
    [null, true, '', 'alice@a.example', 0, true],
    [null, false, 'alice@a.example', 'alice@a.example', 999000, false],
    [null, false, 'bob@a.example', 'alice@a.example', 999000, true],
    [null, false, 'alice@alias.example', 'alice@canonical.example', 999000, true],
    [null, false, 'alice@a.example', 'alice@a.example', 0, true],
    [null, false, '', 'alice@a.example', 0, true],
    [null, false, '', 'alice@a.example', 999000, false],
    [null, false, 'alice@a.example', 'alice@social.a.example', 999000, true]
]);

t_group('decide / http result');

t_table('ActivityPub_Push_State', 'ActivityPub_Push_State', [
    [200, 'ok'], [202, 'ok'], [299, 'ok'],
    [301, 'failed'], [302, 'failed'],
    [400, 'rejected'], [403, 'rejected'], [413, 'rejected'], [422, 'rejected'], [501, 'rejected'],
    [401, 'failed'], [408, 'failed'], [429, 'failed'],
    [404, 'failed'], [405, 'failed'], [410, 'failed'],
    [500, 'failed'], [502, 'failed'], [503, 'failed'], [0, 'failed']
]);

t_group('decide / backoff');

t_table('Club_Endpoint_Backoff', 'Club_Endpoint_Backoff', [
    ['blocked', 0, 1, [3600, false]],
    ['blocked', 7200, 3, [3600, false]],
    ['blocked', 7201, 2, [3600, false]],
    ['blocked', 7201, 3, [3600, true]],
    ['unresolved', 0, 1, [300, false]],
    ['unresolved', 172799, 1, [300, false]],
    ['unresolved', 172800, 1, [3600, false]],
    ['unresolved', 604800, 1, [21600, false]],
    ['unresolved', 2592001, 6, [21600, false]],
    ['unresolved', 2592001, 7, [21600, true]],
    ['failed', 0, 1, [60, false]],
    ['failed', 300, 1, [300, false]],
    ['failed', 1800, 1, [600, false]],
    ['failed', 7200, 1, [900, false]],
    ['failed', 604801, 6, [900, false]],
    ['failed', 604801, 7, [900, true]]
]);

t_group('decide / endpoint completion');

$now = 1700000000;
$plan = Club_Endpoint_Decide('ok', 3, $now - 500, $now + 60, 2, $now, 0);
t_is($plan['queue'], 'delete', 'ok deletes the queue row');
t_is($plan['fails'], 0, 'ok clears fails');
t_is($plan['fail_since'], 0, 'ok clears fail_since');
t_is($plan['retry_at'], 0, 'ok clears retry_at');
t_is($plan['state'], 'recovered', 'ok reports recovery');
t_is($plan['age'], 500, 'ok reports the outage age');
t_is($plan['blacklist'], false, 'ok never blacklists');

$plan = Club_Endpoint_Decide('ok', 0, 0, 0, 0, $now, 0);
t_is($plan['queue'], 'delete', 'ok on a healthy endpoint still deletes the row');
t_is($plan['endpoint'], false, 'ok on a healthy endpoint writes nothing');
t_is($plan['state'], '', 'ok on a healthy endpoint is not a state change');

$plan = Club_Endpoint_Decide('rejected', 4, $now - 900, $now + 60, 1, $now, 0);
t_is($plan['queue'], 'delete', 'rejected deletes the queue row');
t_is($plan['fails'], 0, 'rejected clears fails');
t_is($plan['state'], 'recovered', 'rejected also ends the outage');

$plan = Club_Endpoint_Decide('dropped', 4, $now - 900, $now + 60, 1, $now, 0);
t_is($plan['queue'], 'delete', 'dropped deletes the queue row');
t_is($plan['fails'], 4, 'dropped keeps fails');
t_is($plan['fail_since'], $now - 900, 'dropped keeps fail_since');
t_is($plan['endpoint'], false, 'dropped writes nothing to the endpoint');

$plan = Club_Endpoint_Decide('local-dns', 4, $now - 900, 0, 1, $now, 0);
t_is($plan['queue'], 'defer', 'local-dns defers the queue row');
t_is($plan['due_at'], $now + 300, 'local-dns defers by five minutes');
t_is($plan['fails'], 4, 'local-dns does not count against the target');
t_is($plan['retries'], 1, 'local-dns does not count against the row');
t_is($plan['retry_at'], $now + 300, 'local-dns pushes the whole endpoint');
$plan = Club_Endpoint_Decide('local-dns', 4, $now - 900, $now + 9000, 1, $now, 0);
t_is($plan['retry_at'], $now + 9000, 'local-dns never pulls a longer backoff forward');
t_is($plan['endpoint'], false, 'local-dns writes nothing when the backoff is already longer');
t_is(Club_Endpoint_Decide('local-proxy', 4, $now - 900, $now + 9000, 1, $now, 0), $plan, 'a local proxy failure preserves queue retries and endpoint health');

$plan = Club_Endpoint_Decide('failed', 0, 0, 0, 0, $now, 0);
t_is($plan['fails'], 1, 'first failure counts one');
t_is($plan['fail_since'], $now, 'first failure starts the outage clock');
t_is($plan['retry_at'], $now + 60, 'first failure waits the shortest step');
t_is($plan['queue'], 'retry', 'first failure keeps the row for a retry');
t_is($plan['retries'], 1, 'first failure charges the row once');
t_is($plan['state'], 'failing', 'first failure is a state change');

$plan = Club_Endpoint_Decide('failed', 5, $now - 100, $now, 1, $now, 0);
t_is($plan['fails'], 6, 'later failures keep counting on the endpoint');
t_is($plan['fail_since'], $now - 100, 'later failures keep the original outage start');
t_is($plan['retries'], 1, 'later failures in the same outage do not charge the row again');
t_is($plan['state'], 'still-failing', 'later failures are not a new state change');

$plan = Club_Endpoint_Decide('failed', 5, 0, 0, 7, $now, 0);
t_is($plan['retries'], 8, 'the eighth attempt is the last one');
t_is($plan['queue'], 'delete', 'an exhausted row is dropped');
t_is($plan['blacklist'], false, 'an exhausted row does not blacklist the target');
t_is($plan['state'], 'exhausted', 'an exhausted row reports itself');

$plan = Club_Endpoint_Decide('failed', 7, $now - 604801, 0, 0, $now, 0);
t_is($plan['blacklist'], true, 'a week of failures blacklists the endpoint');
t_is($plan['state'], 'blacklisted', 'blacklisting reports itself');
t_is($plan['queue'], 'keep', 'blacklisting leaves the backlog for the maintenance batch');
t_is(Club_Endpoint_Decide('failed', 6, $now - 604801, 0, 0, $now, 0)['blacklist'], true, 'the seventh failure over a week is enough');
t_is(Club_Endpoint_Decide('failed', 5, $now - 604801, 0, 0, $now, 0)['blacklist'], false, 'six failures over a week is not enough yet');
t_is(Club_Endpoint_Decide('failed', 20, $now - 600, 0, 0, $now, 0)['blacklist'], false, 'a short outage never blacklists no matter how many failures');
$plan = Club_Endpoint_Decide('failed', 0, 0, 0, 0, $now);
t_ok($plan['retry_at'] >= $now + 60 && $plan['retry_at'] <= $now + 75, 'jitter stays inside a quarter of the step');

t_group('decide / blacklist probe');

$plan = Club_Blacklist_Decide(false, 0, false, $now, 0);
t_is($plan['state'], 'dead', 'a silent target stays dead');
t_is($plan['checks'], 1, 'a failed probe counts');
t_is($plan['check_at'], $now + 86400, 'the first retry is a day out');
t_is(Club_Blacklist_Decide(false, 6, false, $now, 0)['check_at'], $now + 86400 * 7, 'the interval widens with checks');
t_is(Club_Blacklist_Decide(false, 20, false, $now, 0)['check_at'], $now + 86400 * 7, 'the interval is capped at a week');
t_is(Club_Blacklist_Decide(true, 3, true, $now)['state'], 'pending', 'a live target with a backlog waits for cleanup');
t_is(Club_Blacklist_Decide(true, 3, true, $now)['check_at'], null, 'a live target schedules no further probe');
t_is(Club_Blacklist_Decide(true, 3, false, $now)['state'], 'restored', 'a live target with no backlog is restored');

t_group('decide / reconcile and prune');

$t_schedule = ['next_at' => 100, 'follow_at' => null, 'notice_at' => null, 'announce_at' => null, 'relay_at' => 100];
$t_scheduled = $t_schedule + ['idle_since' => 0];
$t_empty = ['next_at' => null, 'follow_at' => null, 'notice_at' => null, 'announce_at' => null, 'relay_at' => null];
t_is(Club_Endpoint_Drifted($t_schedule, $t_scheduled), false, 'a categorized schedule that matches is not drifted');
t_is(Club_Endpoint_Drifted($t_empty, $t_empty + ['idle_since' => 5]), false, 'an idle row that matches is not drifted');
t_is(Club_Endpoint_Drifted($t_schedule, array_merge($t_scheduled, ['next_at' => 200])), true, 'a wrong next_at is drift');
t_is(Club_Endpoint_Drifted($t_schedule, array_merge($t_scheduled, ['relay_at' => 200])), true, 'a wrong category time is drift');
t_is(Club_Endpoint_Drifted($t_empty, $t_empty + ['idle_since' => 0]), true, 'an idle row with no idle clock is drift');
t_is(Club_Endpoint_Drifted($t_schedule, $t_schedule + ['idle_since' => 5]), true, 'a scheduled row with an idle clock is drift');

t_is(Club_Task_Category(['type' => 'Accept'], true), 'follow', 'Accept is routed to follow');
t_is(Club_Task_Category(['type' => 'Update'], false), 'relay', 'an actor Update uses the high-fanout relay queue');
t_is(Club_Task_Category(['type' => 'Create'], true), 'notice', 'a direct local activity is routed to notice');
t_is(Club_Task_Category(['type' => 'Announce'], false), 'announce', 'a fanout local activity is routed to announce');
t_is(Club_Task_Category('{"type":"Create"}', false), 'relay', 'an untouched remote payload is routed to relay');

$t_config = $config;
$config['worker']['delivery'] = 8;
t_is(Club_Config_Delivery_Workers(), ['follow' => 2, 'notice' => 2, 'announce' => 2, 'relay' => 2], 'a legacy total splits evenly across delivery types');
$config['worker']['delivery'] = 9;
t_is(Club_Config_Delivery_Workers(), ['follow' => 2, 'notice' => 2, 'announce' => 3, 'relay' => 2], 'an odd legacy total gives the extra worker to announce');
$config = $t_config;

$before = $now - 604800;
t_is(Club_Endpoint_Prune_Decide(0, $before - 1, 0, 0, 0, $now, $before), 'prune', 'an endpoint idle past the grace period is removed');
t_is(Club_Endpoint_Prune_Decide(0, $before + 1, 0, 0, 0, $now, $before), false, 'an endpoint inside the grace period is kept');
t_is(Club_Endpoint_Prune_Decide(0, 0, 0, 0, 0, $now, $before), 'idle', 'a row with no idle clock gets one');
t_is(Club_Endpoint_Prune_Decide(0, $before - 1, 0, 1, 0, $now, $before), false, 'an endpoint with queued work is kept');
t_is(Club_Endpoint_Prune_Decide(0, $before - 1, 0, 0, 1, $now, $before), false, 'a blacklisted endpoint is left to the cleanup batch');
t_is(Club_Endpoint_Prune_Decide($now + 60, $before - 1, 0, 0, 0, $now, $before), false, 'a leased endpoint belongs to its owner');
t_is(Club_Endpoint_Prune_Decide(null, 0, 0, 0, 0, $now, $before), false, 'a missing row is nothing to prune');
t_is(Club_Endpoint_Prune_Decide(0, $before - 1, $now + 60, 0, 0, $now, $before), false, 'an endpoint still backing off is kept');

t_group('decide / relay gate');

$signed = ['signature' => ['signatureValue' => 'x']];
t_is(Club_Inbox_Relayable($signed, str_repeat('x', 100), 'https://a.example/s/1', 'Update'), true, 'a signed payload under the limit is relayed');
t_is(Club_Inbox_Relayable([], str_repeat('x', 100), 'https://a.example/s/1', 'Update'), false, 'an unsigned payload is never relayed');
t_is(Club_Inbox_Relayable($signed, str_repeat('x', 512 * 1024 + 1), 'https://a.example/s/1', 'Delete'), false, 'an oversized payload is never relayed');

$question = ['type' => 'Question', 'oneOf' => [[]]];
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000'], $question), 1700000000, 'a tally carries its revision in the id');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000', 'published' => 'x'], $question), 0, 'an edit is not a tally');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000'], ['type' => ['Question'], 'anyOf' => [[]]]), 1700000000, 'type may be a list');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000'], ['type' => 'Note']), 0, 'a note is not a tally');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000'], ['type' => 'Question']), 0, 'a question without options is not a tally');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1'], $question), 0, 'a tally without a revision suffix is ignored');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/'.(time() + 200000)], $question), 0, 'a far-future revision is ignored');

t_group('decide / lease pick');

$tried = [];
$attempt = function ($url) use (&$tried) { $tried[] = $url; return $url === 'c'; };
t_is(Club_Lease_Pick(['a', 'b', 'c'], '00'.str_repeat('0', 30), $attempt), 'c', 'Club_Lease_Pick returns the candidate it won');
t_is($tried, ['a', 'b', 'c'], 'Club_Lease_Pick walks candidates in order from its start offset');
t_is(Club_Lease_Pick(['a', 'b', 'c'], '00'.str_repeat('0', 30), function () { return false; }), null, 'Club_Lease_Pick gives up when every candidate is taken');
t_is(Club_Lease_Pick(['a', 'b', 'c'], '01'.str_repeat('0', 30), function () { return true; }), 'b', 'Club_Lease_Pick starts where the token points');
t_is(Club_Lease_Pick(['a', 'b', 'c'], '02'.str_repeat('0', 30), function () { return true; }), 'c', 'Club_Lease_Pick wraps around the candidate list');

t_group('config / startup check');

function t_config_problems($override) {
    global $config;
    $saved = $config;
    $config = t_config_merge($config, $override);
    list($fatal, $warn) = Club_Config_Validate();
    $config = $saved;
    return [array_values(array_filter($fatal, function ($problem) { return strpos($problem, 'ext-') !== 0; })), $warn];
}

function t_config_merge($base, $override) {
    foreach ($override as $key => $value) {
        if ($value === '@unset') unset($base[$key]);
        elseif (is_array($value) && $value && isset($base[$key]) && is_array($base[$key])) $base[$key] = t_config_merge($base[$key], $value);
        else $base[$key] = $value;
    }
    return $base;
}

function t_config_mentions($problems, $needle) {
    foreach ($problems as $problem) if (strpos($problem, $needle) !== false) return true;
    return false;
}

list($fatal, $warn) = t_config_problems([]);
t_is($fatal, [], 'a good config has no fatal problems');
t_is($warn, [], 'a good config has no warnings');
foreach (['http://proxy:3128', 'socks5://user:p%40ss@[::1]:1080', 'http://user:password@proxy_service:8000/'] as $proxy)
    t_is(t_config_problems(['node' => ['proxy' => $proxy]]), [[], []], 'a valid proxy URL passes startup validation');
$saved_node = $config['node']; $proxy = 'socks5://proxy:1080'; $dns_budget = Club_Resolver_Budget();
foreach (['proxy-only' => [$proxy], 'direct-first' => ['', $proxy], 'proxy-first' => [$proxy, '']] as $mode => $routes) {
    $config['node']['proxy-mode'] = $mode;
    foreach (['federation', 'doh', 'all'] as $scope) {
        $config['node']['proxy'] = $proxy; $config['node']['proxy-scope'] = $scope;
        t_is(Club_Config_Proxy_Routes('federation'), $scope === 'doh' ? [''] : $routes, $scope.'/'.$mode.' federation route order');
        t_is(Club_Config_Proxy_Routes('doh'), $scope === 'federation' ? [''] : $routes, $scope.'/'.$mode.' DoH route order');
        t_is(Club_Resolver_Budget(), $dns_budget * ($scope === 'federation' ? 1 : count($routes)), $scope.'/'.$mode.' DNS lease covers all attempts');
        $config['node']['proxy'] = '';
        t_is([Club_Config_Proxy_Routes('federation'), Club_Config_Proxy_Routes('doh')], [[''], ['']], $scope.'/'.$mode.' stays direct without a proxy URL');
    }
}
$config['node']['proxy'] = $proxy; unset($config['node']['proxy-scope'], $config['node']['proxy-mode']);
t_is([Club_Config_Proxy_Routes('federation'), Club_Config_Proxy_Routes('doh')], [[$proxy], [$proxy]], 'a proxy URL alone covers both with no direct fallback');
$config['node'] = $saved_node;

foreach ([
    ['config.base', ['base' => '']],
    ['config.base', ['base' => '@unset']],
    ['config.mysql.host', ['mysql' => ['host' => '@unset']]],
    ['config.mysql.password', ['mysql' => ['password' => null]]],
    ['config.node.timezone', ['node' => ['timezone' => 'Mars/Olympus']]],
    ['config.node.timezone', ['node' => ['timezone' => '@unset']]],
    ['config.node.proxy', ['node' => ['proxy' => 'socks5h://proxy:1080']]],
    ['config.node.proxy', ['node' => ['proxy' => 'http://proxy:65536']]],
    ['config.node.proxy', ['node' => ['proxy' => 'http://:3128']]],
    ['config.node.proxy', ['node' => ['proxy' => 'http://proxy:3128/path']]],
    ['config.node.proxy', ['node' => ['proxy' => []]]],
    ['config.node.proxy', ['node' => ['proxy' => null]]],
    ['config.node.proxy-scope', ['node' => ['proxy-scope' => 'dns']]],
    ['config.node.proxy-scope', ['node' => ['proxy-scope' => null]]],
    ['config.node.proxy-mode', ['node' => ['proxy-mode' => 'auto']]],
    ['config.club.suspended-names', ['club' => ['suspended-names' => '@unset']]]
] as $case) {
    list($fatal, $warn) = t_config_problems($case[1]);
    t_ok(t_config_mentions($fatal, $case[0]), 'fatal: '.$case[0].' '.json_encode($case[1]));
}

foreach ([
    ['config.node.log-level', ['node' => ['log-level' => 'verbose']]],
    ['config.node.proxy', ['node' => ['proxy' => '@unset']]],
    ['config.node.proxy-scope', ['node' => ['proxy' => 'http://proxy:3128', 'proxy-scope' => '@unset']]],
    ['config.node.proxy-mode', ['node' => ['proxy' => 'http://proxy:3128', 'proxy-mode' => '@unset']]],
    ['config.node.language', ['node' => ['language' => 'kl']]],
    ['config.node.log-retention', ['node' => ['log-retention' => '30 days']]],
    ['config.worker.delivery', ['worker' => ['delivery' => 0]]],
    ['config.worker.delivery.follow', ['worker' => ['delivery' => ['follow' => 0]]]],
    ['config.worker.probe', ['worker' => ['probe' => -1]]],
    ['config.dns.resolver', ['dns' => ['resolver' => []]]],
    ['config.dns.resolver[0].url', ['dns' => ['resolver' => [['url' => 'http://insecure.example/dns', 'ip' => []]]]]],
    ['config.dns.resolver[0].ip', ['dns' => ['resolver' => [['url' => 'https://a.example/dns', 'ip' => ['not-an-ip']]]]]],
    ['config.dns.timeout', ['dns' => ['timeout' => 0]]],
    ['config.club.relay-limit', ['club' => ['relay-limit' => 0]]],
    ['config.club.create-limit', ['club' => ['create-limit' => -1]]],
    ['config.club.system-name', ['club' => ['system-name' => 'has/slash']]],
    ['config.club.open-registrations', ['club' => ['open-registrations' => '@unset']]],
    ['config.node.name', ['node' => ['name' => '@unset']]],
    ['config.node.maintainer.email', ['node' => ['maintainer' => ['email' => '@unset']]]],
    ['config.default.avatar', ['default' => ['avatar' => '@unset']]],
    ['config.notice.limit', ['notice' => ['limit' => 'twenty']]],
    ['config.club.limits.busy[0].type', ['club' => ['limits' => ['busy' => ['type' => 'users', 'hours' => 1, 'limit' => 1]]]]],
    ['config.club.limits.busy[1]', ['club' => ['limits' => ['busy' => [['type' => 'user', 'hours' => 1, 'limit' => 1], ['type' => 'site', 'hours' => 0, 'limit' => 5]]]]]]
] as $case) {
    list($fatal, $warn) = t_config_problems($case[1]);
    t_ok(t_config_mentions($warn, $case[0]), 'warning: '.$case[0].' '.json_encode($case[1]));
    t_is($fatal, [], 'no fatal for '.$case[0].' '.json_encode($case[1]));
}

t_is(Club_Config_Number(10, 1), 10, 'Club_Config_Number accepts an integer');
t_is(Club_Config_Number('10', 1), 10, 'Club_Config_Number accepts an integer string');
t_is(Club_Config_Number(0, 0), 0, 'Club_Config_Number accepts zero when zero is allowed');
t_is(Club_Config_Number(0, 1), false, 'Club_Config_Number rejects below the minimum');
t_is(Club_Config_Number(true, 0), false, 'Club_Config_Number rejects a boolean');
t_is(Club_Config_Number('10 hours', 1), false, 'Club_Config_Number rejects a trailing unit');
t_is(Club_Config_Number(1.5, 1), false, 'Club_Config_Number rejects a float');
