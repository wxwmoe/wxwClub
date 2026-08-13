<?php

/* 不碰数据库、不出网的那一半。7.3 到 8.4 都要跑得过：这里抓的正是 lint 看不见、又跟 PHP 版本相关的东西（parse_url 对空 query 的形状、inet_pton 的写法归一）。
 * 分三段：纯函数、状态决策、配置校验。 */

t_group('pure / endpoint normalize');

// 只合并协议上必然等价的写法；path 和 query 一个字节都不能动
t_table('Club_Endpoint_Normalize', 'Club_Endpoint_Normalize', [
    ['https://a.example/inbox', 'https://a.example/inbox'],
    ['HTTPS://A.Example/inbox', 'https://a.example/inbox'],
    ['https://a.example:443/inbox', 'https://a.example/inbox'],
    ['http://a.example:80/inbox', 'http://a.example/inbox'],
    ['https://a.example:8443/inbox', 'https://a.example:8443/inbox'],
    ['https://a.example', 'https://a.example/'],
    // 反向代理完全可以让 /Inbox 和 /inbox 落到两个应用
    ['https://a.example/Inbox', 'https://a.example/Inbox'],
    ['https://a.example/inbox?x=0', 'https://a.example/inbox?x=0'],
    // 尾随 ? 是一个真实的空 query，7.3 的 parse_url 会把它丢掉
    ['https://a.example/inbox?', 'https://a.example/inbox?'],
    ['https://[2001:0db8:0000:0000:0000:0000:0000:0001]/inbox', 'https://[2001:db8::1]/inbox'],
    ['https://a.example./inbox', 'https://a.example./inbox'],
    ['https://a_b.example/inbox', 'https://a_b.example/inbox'],
    // fragment 即使为空也不能进 endpoint
    ['https://a.example/inbox#frag', false],
    ['https://a.example/inbox#', false],
    // userinfo 能把 curl 带去另一个 host
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
// 列宽就是 255，超了写进去是被截断的半截 URL
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

// PHP 的 NO_PRIV_RANGE/NO_RES_RANGE 漏掉的那些段，正是这张表要盯住的
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
// 长度不同的两族不能互相匹配
t_is(Club_IP_Matches(inet_pton('::1'), '10.0.0.0', 8), false, 'Club_IP_Matches across address families');

// 只测 IP 字面量：域名要走 Club_Resolver_Get，那是数据库和 DoH 的事
t_is(Club_Url_Public('https://1.1.1.1/inbox'), ['1.1.1.1'], 'Club_Url_Public keeps a public literal');
t_is(Club_Url_Public('http://127.0.0.1/inbox'), false, 'Club_Url_Public blocks loopback');
t_is(Club_Url_Public('https://[::1]/inbox'), false, 'Club_Url_Public blocks IPv6 loopback');
t_is(Club_Url_Public('https://169.254.169.254/latest/meta-data/'), false, 'Club_Url_Public blocks link-local metadata');
t_is(Club_Url_Public('file:///etc/passwd'), false, 'Club_Url_Public blocks non-http schemes');

t_group('pure / http and json shapes');

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

t_table('Club_HTTP_Cursor', 'Club_HTTP_Cursor', [
    ['123.45', [123, 45]],
    ['0.0', [0, 0]],
    ['01.02', [1, 2]],
    ['1.2.3', false],
    ['x', false],
    ['', false],
    [123, false],
    // ?max[]=1 这种数组参数不能被强制转字符串
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

t_table('Club_I18n_Match', 'Club_I18n_Match', [
    ['zh', 'zh-CN'], ['zh-Hant', 'zh-TW'], ['ZH_TW', 'zh-TW'], ['zh-mo', 'zh-HK'],
    ['yue', 'zh-HK'], ['en-GB', 'en'], ['ja-JP', 'ja'], ['fr', false], ['', false]
]);

t_table('Club_Log_Slug', 'Club_Log_Slug', [['a/b', 'aⳆb'], ['a\\b', 'aⳆb'], ["a\tb", 'a_b'], ['a*b?[c]', 'abc'], ["a\x00b", 'ab']]);

t_group('pure / date and digest');

t_is(ActivityPub_Verify_Date(gmdate('D, d M Y H:i:s T')), true, 'ActivityPub_Verify_Date accepts now');
t_is(ActivityPub_Verify_Date(gmdate('D, d M Y H:i:s T', time() - 299)), true, 'ActivityPub_Verify_Date accepts the edge of the window');
t_is(ActivityPub_Verify_Date(gmdate('D, d M Y H:i:s T', time() - 400)), false, 'ActivityPub_Verify_Date rejects a stale date');
t_is(ActivityPub_Verify_Date(gmdate('D, d M Y H:i:s T', time() + 400)), false, 'ActivityPub_Verify_Date rejects a future date');
// (created) 是 unix 时间戳，不是 HTTP 日期
t_is(ActivityPub_Verify_Date((string)time()), true, 'ActivityPub_Verify_Date accepts a unix timestamp');
t_is(ActivityPub_Verify_Date((string)(time() - 1000)), false, 'ActivityPub_Verify_Date rejects a stale unix timestamp');
t_is(ActivityPub_Verify_Date(''), false, 'ActivityPub_Verify_Date rejects an empty date');
t_is(ActivityPub_Verify_Date('garbage'), false, 'ActivityPub_Verify_Date rejects an unparsable date');

$body = '{"type":"Follow"}';
$_SERVER['HTTP_DIGEST'] = 'SHA-256='.base64_encode(hash('sha256', $body, true));
t_is(ActivityPub_Verify_Digest($body), true, 'ActivityPub_Verify_Digest accepts a matching sha-256');
t_is(ActivityPub_Verify_Digest($body.' '), false, 'ActivityPub_Verify_Digest rejects a modified body');
$_SERVER['HTTP_DIGEST'] = 'SHA-512='.base64_encode(hash('sha512', $body, true));
t_is(ActivityPub_Verify_Digest($body), true, 'ActivityPub_Verify_Digest accepts sha-512');
// algorithm 是对端给的，不能直接透传给 hash()
$_SERVER['HTTP_DIGEST'] = 'MD5='.base64_encode(hash('md5', $body, true));
t_is(ActivityPub_Verify_Digest($body), false, 'ActivityPub_Verify_Digest rejects an unsupported algorithm');
$_SERVER['HTTP_DIGEST'] = 'garbage';
t_is(ActivityPub_Verify_Digest($body), false, 'ActivityPub_Verify_Digest rejects a malformed header');
unset($_SERVER['HTTP_DIGEST']);

t_group('pure / doh answers');

// 三态：数组是地址，null 是 SERVFAIL（对端的事），false 是这家没答上来（本站的事）
t_table('Club_Resolver_Answer', 'Club_Resolver_Answer', [
    [null, 'A', false],
    [['nostatus' => 1], 'A', false],
    [['Status' => 2], 'A', null],
    [['Status' => 5], 'A', false],
    [['Status' => 3], 'A', []],
    // NXDOMAIN 带地址记录是自相矛盾的报文，那个地址下一步就要钉给 curl
    [['Status' => 3, 'Answer' => [['type' => 1, 'data' => '1.2.3.4']]], 'A', []],
    [['Status' => 0, 'TC' => true, 'Answer' => [['type' => 1, 'data' => '1.2.3.4']]], 'A', false],
    [['Status' => 0, 'Answer' => 'not-a-list'], 'A', false],
    [['Status' => 0], 'A', []],
    [['Status' => 0, 'Answer' => [['type' => 5, 'data' => 'x.example'], ['type' => 1, 'data' => '1.2.3.4']]], 'A', ['1.2.3.4']],
    // type 按数值比，一个把它序列化成字符串的 resolver 不该让好域名整片进负缓存
    [['Status' => 0, 'Answer' => [['type' => '1', 'data' => '1.2.3.4']]], 'A', ['1.2.3.4']],
    [['Status' => 0, 'Answer' => [['type' => 1, 'data' => 'garbage']]], 'A', false],
    [['Status' => 0, 'Answer' => ['scalar']], 'A', false],
    // 只有 CNAME 是合规的「没有这种记录」
    [['Status' => 0, 'Answer' => [['type' => 5, 'data' => 'x.example']]], 'A', []],
    [['Status' => 0, 'Answer' => [['type' => 28, 'data' => '2001:db8::1']]], 'AAAA', ['2001:db8::1']],
    [['Status' => 0, 'Answer' => [['type' => 1, 'data' => '1.2.3.4']]], 'AAAA', []]
]);

t_group('decide / http result');

// 划错一档的代价：整家实例被误判成挂了，或者一条谁都不收的活动无限重投
t_table('ActivityPub_Push_State', 'ActivityPub_Push_State', [
    [200, 'ok'], [202, 'ok'], [299, 'ok'],
    // POST 从不跟跳转，3xx 当成功就是这一行当场被删掉而谁都没收到
    [301, 'failed'], [302, 'failed'],
    [400, 'rejected'], [403, 'rejected'], [413, 'rejected'], [422, 'rejected'], [501, 'rejected'],
    // 这三个是「过一会儿可能就好了」，不能归进终局
    [401, 'failed'], [408, 'failed'], [429, 'failed'],
    // inbox 不在了，不是这一条活动不被收
    [404, 'failed'], [405, 'failed'], [410, 'failed'],
    [500, 'failed'], [502, 'failed'], [503, 'failed'], [0, 'failed']
]);

t_group('decide / backoff');

// 档位读故障年龄不读次数；放弃前仍要求最低采样数
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
// 投成功：删这一行，清掉整条 endpoint 的故障段
$plan = Club_Endpoint_Decide('ok', 3, $now - 500, $now + 60, 2, $now, 0);
t_is($plan['queue'], 'delete', 'ok deletes the queue row');
t_is($plan['fails'], 0, 'ok clears fails');
t_is($plan['fail_since'], 0, 'ok clears fail_since');
t_is($plan['retry_at'], 0, 'ok clears retry_at');
t_is($plan['state'], 'recovered', 'ok reports recovery');
t_is($plan['age'], 500, 'ok reports the outage age');
t_is($plan['blacklist'], false, 'ok never blacklists');

// 从来没失败过的成功投递不必写 endpoints
$plan = Club_Endpoint_Decide('ok', 0, 0, 0, 0, $now, 0);
t_is($plan['queue'], 'delete', 'ok on a healthy endpoint still deletes the row');
t_is($plan['endpoint'], false, 'ok on a healthy endpoint writes nothing');
t_is($plan['state'], '', 'ok on a healthy endpoint is not a state change');

// 对端应用层的终局拒绝：这一条永远不会被收，但整家是好的，故障段照样该结束
$plan = Club_Endpoint_Decide('rejected', 4, $now - 900, $now + 60, 1, $now, 0);
t_is($plan['queue'], 'delete', 'rejected deletes the queue row');
t_is($plan['fails'], 0, 'rejected clears fails');
t_is($plan['state'], 'recovered', 'rejected also ends the outage');

// 本地就处理不掉的一行，什么都没证明，不能顺手把故障段清掉
$plan = Club_Endpoint_Decide('dropped', 4, $now - 900, $now + 60, 1, $now, 0);
t_is($plan['queue'], 'delete', 'dropped deletes the queue row');
t_is($plan['fails'], 4, 'dropped keeps fails');
t_is($plan['fail_since'], $now - 900, 'dropped keeps fail_since');
t_is($plan['endpoint'], false, 'dropped writes nothing to the endpoint');

// 本站 DNS 的问题：不记对端的账，只把整条 endpoint 往后推
$plan = Club_Endpoint_Decide('local-dns', 4, $now - 900, 0, 1, $now, 0);
t_is($plan['queue'], 'defer', 'local-dns defers the queue row');
t_is($plan['due_at'], $now + 300, 'local-dns defers by five minutes');
t_is($plan['fails'], 4, 'local-dns does not count against the target');
t_is($plan['retries'], 1, 'local-dns does not count against the row');
t_is($plan['retry_at'], $now + 300, 'local-dns pushes the whole endpoint');
// 已经退得更远的不能被拉回来
$plan = Club_Endpoint_Decide('local-dns', 4, $now - 900, $now + 9000, 1, $now, 0);
t_is($plan['retry_at'], $now + 9000, 'local-dns never pulls a longer backoff forward');
t_is($plan['endpoint'], false, 'local-dns writes nothing when the backoff is already longer');

// 故障段的第一笔
$plan = Club_Endpoint_Decide('failed', 0, 0, 0, 0, $now, 0);
t_is($plan['fails'], 1, 'first failure counts one');
t_is($plan['fail_since'], $now, 'first failure starts the outage clock');
t_is($plan['retry_at'], $now + 60, 'first failure waits the shortest step');
t_is($plan['queue'], 'retry', 'first failure keeps the row for a retry');
t_is($plan['retries'], 1, 'first failure charges the row once');
t_is($plan['state'], 'failing', 'first failure is a state change');

// 同一个故障段里的后续失败：endpoint 的计数涨，单行的 retries 不涨
$plan = Club_Endpoint_Decide('failed', 5, $now - 100, $now, 1, $now, 0);
t_is($plan['fails'], 6, 'later failures keep counting on the endpoint');
t_is($plan['fail_since'], $now - 100, 'later failures keep the original outage start');
t_is($plan['retries'], 1, 'later failures in the same outage do not charge the row again');
t_is($plan['state'], 'still-failing', 'later failures are not a new state change');

// 一直投不出去的单行：按行放弃，不牵连这家的其他投递
$plan = Club_Endpoint_Decide('failed', 5, 0, 0, 7, $now, 0);
t_is($plan['retries'], 8, 'the eighth attempt is the last one');
t_is($plan['queue'], 'delete', 'an exhausted row is dropped');
t_is($plan['blacklist'], false, 'an exhausted row does not blacklist the target');
t_is($plan['state'], 'exhausted', 'an exhausted row reports itself');

// 挂了一周还没回来，采样也够了：整条 endpoint 判死刑
$plan = Club_Endpoint_Decide('failed', 7, $now - 604801, 0, 0, $now, 0);
t_is($plan['blacklist'], true, 'a week of failures blacklists the endpoint');
t_is($plan['state'], 'blacklisted', 'blacklisting reports itself');
t_is($plan['queue'], 'keep', 'blacklisting leaves the backlog for the maintenance batch');
// 采样不够不能放弃：一次网络抖动下几十个在途会把 fails 一次加满
t_is(Club_Endpoint_Decide('failed', 6, $now - 604801, 0, 0, $now, 0)['blacklist'], true, 'the seventh failure over a week is enough');
t_is(Club_Endpoint_Decide('failed', 5, $now - 604801, 0, 0, $now, 0)['blacklist'], false, 'six failures over a week is not enough yet');
// 时长和采样是「与」，缺一样都不能判死刑
t_is(Club_Endpoint_Decide('failed', 20, $now - 600, 0, 0, $now, 0)['blacklist'], false, 'a short outage never blacklists no matter how many failures');
// 抖动只往后不往前
$plan = Club_Endpoint_Decide('failed', 0, 0, 0, 0, $now);
t_ok($plan['retry_at'] >= $now + 60 && $plan['retry_at'] <= $now + 75, 'jitter stays inside a quarter of the step');

t_group('decide / blacklist probe');

$plan = Club_Blacklist_Decide(false, 0, false, $now, 0);
t_is($plan['state'], 'dead', 'a silent target stays dead');
t_is($plan['checks'], 1, 'a failed probe counts');
t_is($plan['check_at'], $now + 86400, 'the first retry is a day out');
t_is(Club_Blacklist_Decide(false, 6, false, $now, 0)['check_at'], $now + 86400 * 7, 'the interval widens with checks');
t_is(Club_Blacklist_Decide(false, 20, false, $now, 0)['check_at'], $now + 86400 * 7, 'the interval is capped at a week');
// 活过来了但历史 backlog 还在：先只记状态，等清干净再解禁
t_is(Club_Blacklist_Decide(true, 3, true, $now)['state'], 'pending', 'a live target with a backlog waits for cleanup');
t_is(Club_Blacklist_Decide(true, 3, true, $now)['check_at'], null, 'a live target schedules no further probe');
t_is(Club_Blacklist_Decide(true, 3, false, $now)['state'], 'restored', 'a live target with no backlog is restored');

t_group('decide / reconcile and prune');

// 分类提示、汇总时间和 idle_since 必须一起核对，漏一列就会让那一类永远由错误的 worker 领取。
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
// 还有活要投、还在黑名单里、租约没到期，三种都不能动
t_is(Club_Endpoint_Prune_Decide(0, $before - 1, 0, 1, 0, $now, $before), false, 'an endpoint with queued work is kept');
t_is(Club_Endpoint_Prune_Decide(0, $before - 1, 0, 0, 1, $now, $before), false, 'a blacklisted endpoint is left to the cleanup batch');
t_is(Club_Endpoint_Prune_Decide($now + 60, $before - 1, 0, 0, 0, $now, $before), false, 'a leased endpoint belongs to its owner');
t_is(Club_Endpoint_Prune_Decide(null, 0, 0, 0, 0, $now, $before), false, 'a missing row is nothing to prune');
// 退避还没到期的空行留着，回收它就把 fails/fail_since 一起丢了
t_is(Club_Endpoint_Prune_Decide(0, $before - 1, $now + 60, 0, 0, $now, $before), false, 'an endpoint still backing off is kept');

t_group('decide / relay gate');

$signed = ['signature' => ['signatureValue' => 'x']];
t_is(Club_Inbox_Relayable($signed, str_repeat('x', 100), 'https://a.example/s/1', 'Update'), true, 'a signed payload under the limit is relayed');
t_is(Club_Inbox_Relayable([], str_repeat('x', 100), 'https://a.example/s/1', 'Update'), false, 'an unsigned payload is never relayed');
t_is(Club_Inbox_Relayable($signed, str_repeat('x', 512 * 1024 + 1), 'https://a.example/s/1', 'Delete'), false, 'an oversized payload is never relayed');

// 计票包按形状认，不拿库里的 updated 反推
$question = ['type' => 'Question', 'oneOf' => [[]]];
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000'], $question), 1700000000, 'a tally carries its revision in the id');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000', 'published' => 'x'], $question), 0, 'an edit is not a tally');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000'], ['type' => ['Question'], 'anyOf' => [[]]]), 1700000000, 'type may be a list');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000'], ['type' => 'Note']), 0, 'a note is not a tally');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/1700000000'], ['type' => 'Question']), 0, 'a question without options is not a tally');
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1'], $question), 0, 'a tally without a revision suffix is ignored');
// 放一个远期值进去等于把这条帖子后面的计票全挡在门外
t_is(Club_Inbox_Poll(['id' => 'https://a.example/s/1#updates/'.(time() + 200000)], $question), 0, 'a far-future revision is ignored');

t_group('decide / lease pick');

$tried = [];
$attempt = function ($url) use (&$tried) { $tried[] = $url; return $url === 'c'; };
t_is(Club_Lease_Pick(['a', 'b', 'c'], '00'.str_repeat('0', 30), $attempt), 'c', 'Club_Lease_Pick returns the candidate it won');
t_is($tried, ['a', 'b', 'c'], 'Club_Lease_Pick walks candidates in order from its start offset');
t_is(Club_Lease_Pick(['a', 'b', 'c'], '00'.str_repeat('0', 30), function () { return false; }), null, 'Club_Lease_Pick gives up when every candidate is taken');
// token 的头两位散开起点，几十个进程才不会齐步抢同一条
t_is(Club_Lease_Pick(['a', 'b', 'c'], '01'.str_repeat('0', 30), function () { return true; }), 'b', 'Club_Lease_Pick starts where the token points');
t_is(Club_Lease_Pick(['a', 'b', 'c'], '02'.str_repeat('0', 30), function () { return true; }), 'c', 'Club_Lease_Pick wraps around the candidate list');

t_group('config / startup check');

// 环境自带的扩展不该影响判断，本机跑测试时缺 curl / pdo_mysql 是常态
function t_config_problems($override) {
    global $config;
    $saved = $config;
    $config = t_config_merge($config, $override);
    list($fatal, $warn) = Club_Config_Validate();
    $config = $saved;
    return [array_values(array_filter($fatal, function ($problem) { return strpos($problem, 'ext-') !== 0; })), $warn];
}

// '@unset' 表示把这个键整个删掉，其余按层覆盖。空数组是替换而不是合并，否则 'resolver' => [] 会原样留下默认那两家
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

// 无遮拦读到的那几项：缺了本来就起不来，校验只是把崩溃换成一句话
foreach ([
    ['config.base', ['base' => '']],
    ['config.base', ['base' => '@unset']],
    ['config.mysql.host', ['mysql' => ['host' => '@unset']]],
    ['config.mysql.password', ['mysql' => ['password' => null]]],
    ['config.node.timezone', ['node' => ['timezone' => 'Mars/Olympus']]],
    ['config.node.timezone', ['node' => ['timezone' => '@unset']]],
    ['config.club.suspended-names', ['club' => ['suspended-names' => '@unset']]]
] as $case) {
    list($fatal, $warn) = t_config_problems($case[1]);
    t_ok(t_config_mentions($fatal, $case[0]), 'fatal: '.$case[0].' '.json_encode($case[1]));
}

// 有回退的那些：跑得起来，但跑的不是配置里写的那套
foreach ([
    ['config.node.log-level', ['node' => ['log-level' => 'verbose']]],
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

// true / '10 条' 这类会被 (int) 悄悄变成一个能用的数
t_is(Club_Config_Number(10, 1), 10, 'Club_Config_Number accepts an integer');
t_is(Club_Config_Number('10', 1), 10, 'Club_Config_Number accepts an integer string');
t_is(Club_Config_Number(0, 0), 0, 'Club_Config_Number accepts zero when zero is allowed');
t_is(Club_Config_Number(0, 1), false, 'Club_Config_Number rejects below the minimum');
t_is(Club_Config_Number(true, 0), false, 'Club_Config_Number rejects a boolean');
t_is(Club_Config_Number('10 hours', 1), false, 'Club_Config_Number rejects a trailing unit');
t_is(Club_Config_Number(1.5, 1), false, 'Club_Config_Number rejects a float');
