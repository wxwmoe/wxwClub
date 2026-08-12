<?php require_once(__DIR__.'/class/curl.php');

// 这份代码要求的数据库结构版本，对应 src/migrate/ 下最大的那个步骤文件。库里落后就由 worker 合并上来，合并期间 web 全挡：半新半旧的结构下接请求，入站活动会写进本地状态再报错，对端重放就是半处理
define('DB_VERSION', 4);

// 跳转自己跟：交给 curl 的话每一跳既过不了内网检查，签名也对不上新 host。跳数与 Mastodon 一致。
// $fetch_retry 说的是「这次拉不到」还是「这个地址永远拉不到」，两者的自愈概率差好几个数量级：调用方拿它决定给对端 5xx 还是终局 4xx，判错一边就是丢活动或者无限重投。
// 默认终局，只有说得出「过一会儿可能就好了」的失败才置 true
function ActivityPub_GET($url, $club, $hops = 3) {
    global $curl, $fetch_retry;
    $fetch_retry = false;
    for ($i = 0; $i <= $hops; $i++) {
        // 拦下的是内网地址和非 http(s)，属于有人在拿本站当探针，不能只是静默返回
        if (($public = Club_Url_Public($url)) === false) {
            Club_Log_Event('warning', 'fetch blocked, url is not public', ['url' => $url, 'hop' => $i]);
            return false;
        }
        // 解析不出来只是这次拉不到，跟被人当探针不是一回事，别混进 warning 里
        if ($public === null) {
            Club_Log_Event(Club_Resolver_Healthy() ? 'info' : 'warning', 'fetch skipped, cannot resolve host', ['url' => $url, 'hop' => $i]);
            $fetch_retry = true; return false;
        }
        $date = gmdate('D, d M Y H:i:s T');
        // 把验过的 IP 一起交下去，别让 curl 自己再解析一遍
        $result = ActivityPub_CURL($url, $date, ['Signature' => ActivityPub_Signature($url, $club, $date)], null, $public);
        // 有救的只有传输层没通（连不上、超时、TLS，看 curlError）和对端喊等会儿的那几个状态码。不能照 Curl 的返回值判：它把 4xx / 5xx 一律算进 error，
        // 那样 404、410 这种「这个文档永远不在」的终局答复也成了还有救，对端会一直收到 5xx。这条线与投递侧的 rejected 完全互补（501 同样归终局），只多一个 2xx —— 拿到了文档但它不合法，那也是终局
        $code = $curl->httpStatusCode;
        $fetch_retry = $curl->curlError || in_array($code, [401, 408, 429]) || ($code >= 500 && $code != 501);
        if ($result === false || !in_array($code, [301, 302, 303, 307, 308])) return $result;
        if (!($location = Club_Header_Get($curl->responseHeaders, 'Location'))) return $result;
        $url = Club_Url_Absolute($location, $url);
    } return false;
}

// 返回原因码，调用方要分开处置：这几种失败的自愈概率差好几个数量级，共用一套退避阶梯的话，注销了域名的对端会被按「临时挂掉」每分钟重试：
//   ok         成功
//   rejected   对端给的终局答复：它是好的，只是这一条它永远不会收，跟整家的健康无关
//   failed     连不上、超时、inbox 已经不在了，或对端答得不对但还有救
//   unresolved 域名解析不出来，而本站 DNS 是好的
//   blocked    目标指向内网或协议不对，该拦
//   local-dns  本站自己解析不动、或刷新锁在别人手上，什么都没证明，不能算在对端头上
//   lease-lost $authorize 没放行，这次请求根本没发出去
// $authorize 在解析之后、真正出网之前调用。
// 解析、签名、curl 各有各的超时，但合起来没有上界，跨过租约之后这条 endpoint 已经易主，旧 worker 醒过来再发一次，就是同一秒两个进程打同一家 —— 只在落库时验 token 拦不住已经出网的请求
function ActivityPub_POST($url, $club, $jsonld, $authorize = null) {
    global $curl;
    // 队列里的 target 是很久以前拉取的，域名可能已经解析到内网，每次投递都要重判
    if (($public = Club_Url_Public($url)) === false) {
        Club_Log_Event('warning', 'push blocked, url is not public', ['url' => $url, 'club' => $club]);
        return 'blocked';
    }
    if ($public === null) {
        // 刷新锁在别人手上、本地又没有可用旧值：这一轮压根没查 DNS，问健康与否都是错的
        if (Club_Resolver_Deferred()) {
            Club_Log_Event('debug', 'push deferred, dns refresh is held by another worker', ['url' => $url, 'club' => $club]);
            return 'local-dns';
        }
        // 别的域名解析得动，就是这个对端把域名撤了/过期了，跟连不上没区别，照常记失败
        if (Club_Resolver_Healthy()) {
            Club_Log_Event('info', 'push failed, host does not resolve', ['url' => $url, 'club' => $club]);
            return 'unresolved';
        }
        // 一个都解析不动，是本站自己的毛病
        Club_Log_Event('warning', 'push deferred, local dns looks broken', ['url' => $url, 'club' => $club]);
        return 'local-dns';
    }
    // 签名要查一次 clubs 取私钥。放在闸门后面的话，出网许可和 curl 之间还夹着一次数据库往返 —— 它要是卡过了租约，endpoint 已经易主而这边照发不误，等于把好不容易前移的所有权又漏掉一截。
    // 闸门之后不再碰数据库
    $date = gmdate('D, d M Y H:i:s T');
    $digest = base64_encode(hash('sha256', $jsonld, 1));
    $head = ['Signature' => ActivityPub_Signature($url, $club, $date, $digest), 'Digest' => 'SHA-256='.$digest];
    if (isset($authorize) && !$authorize()) return 'lease-lost';
    Club_Stat('http_requests');
    ActivityPub_CURL($url, $date, $head, $jsonld, $public);
    return ActivityPub_Push_Result(isset($curl) ? $curl->httpStatusCode : 0);
}

// 状态码到结果码的那条线，单拎出来是为了能不出网地验：它是一个状态码的纯函数，而划错一档的代价是整家实例被误判成挂了、或者一条谁都不收的活动无限重投。
// 收下了只认 2xx，跟 Mastodon 的 response_successful? 一样。
// 不能照 Curl 的返回值判：它只把 4xx / 5xx 算错误，而 POST 是不跟跳转的，对端回一个 301 就会被当成投递成功、这一行当场删掉，那条活动其实谁都没收到。3xx 归 failed，照常重试
function ActivityPub_Push_Result($code) {
    if ($code >= 200 && $code < 300) return 'ok';
    // 这三个说的是这个 inbox 不在了，不是这一条活动不被收。实例关站之后域名往往还解析得动、还有个静态站或空路由在应答，归进 rejected 就等于每条新活动都再打它一次，是唯一一条没有出口的失败路径。
    // 交给退避阶梯管的正是「以后可能回来」这件事；endpoint 的身份是 URL 本身，个人 inbox 没了只拉黑那一个，同实例的 shared inbox 不受牵连
    if (in_array($code, [404, 405, 410])) return 'failed';
    // 划线跟 Mastodon 的 response_error_unsalvageable? 一致：501 和 4xx（401、408、429 除外）是对端应用层给的终局答复，DNS、TCP、TLS 到应用层全通，它只是永远不会收这一条。
    // 它自己碰到这些也不重试、也不计对端的失败，我们更不能拿它去推整家的熔断和放弃阶梯，否则一条谁都不收的活动就能把好端端一家实例算成挂了。
    // 留下的 401 多半是密钥轮换那类会自愈的，408 和 429 是对端在喊慢一点，都该整家一起退
    if ($code == 501 || ($code >= 400 && $code < 500 && !in_array($code, [401, 408, 429]))) return 'rejected';
    return 'failed';
}

// 黑名单探活只问一件事：对端还在不在。这份最小活动打 inbox 本来就会被 400/401 挡回来，而 Curl 把 4xx 也算进 $error，照投递成功与否来判的话，进了黑名单的实例永远出不来。
// body 不能是空对象：Pleroma 取不到 actor 会在解析阶段 500，而 5xx 在下面算 dead，活着的实例就永远解不了禁。
// 带上 type 和系统群组的 actor —— 跟签名的 keyId 是同一个 —— 对端才走得完解析、给得出那句应用层的拒绝
// 判活的门槛照着投递侧划：2xx，或者应用层还在拒的 4xx —— 拿到这些就说明 DNS、TCP、TLS 到应用层全通。5xx 不算，CDN 回源失败也是有状态码的，那种情况对端其实还是死的。
// 3xx 不算：POST 从不跟跳转，一个只会 301 的旧 inbox 在投递侧永远是 failed。404/405/410 不算，理由同投递侧。
// 两者误判成活的代价是一样的：解禁之后每条新活动都再打它一次，一周后重新拉黑，来回空转 —— 历史 backlog 在解禁前已经清掉，不是它们复活，是新活动在填
// HTTP 之后返回 alive/dead；出网授权前的 blocked/unresolved/local-dns/lease-lost 原样返回，让调用方继续用第一阶段 token，本站 DNS 的问题也不会被记成对端探活失败
function ActivityPub_Probe($url, $club, $authorize = null) {
    global $curl, $base;
    $result = ActivityPub_POST($url, $club, Club_Json_Encode(['@context' => 'https://www.w3.org/ns/activitystreams', 'type' => 'Activity', 'actor' => $base.'/club/'.$club]), $authorize);
    // 这些结果都发生在授权 callback 之前，调用方必须继续使用第一阶段 token。折叠成 dead 会让 completion 误拿尚未安装的新 token，结果永远被 fencing 丢掉。
    if (in_array($result, ['blocked', 'unresolved', 'local-dns', 'lease-lost'], true)) return $result;
    if (!isset($curl) || $curl->curlError) return 'dead';
    $code = $curl->httpStatusCode;
    return ($code >= 200 && $code < 300) || ($code >= 400 && $code < 500 && !in_array($code, [404, 405, 410])) ? 'alive' : 'dead';
}

function ActivityPub_CURL($url, $date, $head, $data = null, $ips = null) {
    global $ver, $base, $curl, $config; static $last_head = [], $pinned = null;
    if (!isset($curl)) $curl = new Curl();
    $curl->setTimeout(10);
    $curl->setConnectTimeout(3);
    // 内网检查的另一半：Club_Url_Public 只交上来公网地址，私网那些是靠「curl 拿不到就连不上」拦住的。不钉的话 curl 会拿 URL 自己再解析一遍，既把剔掉的地址捡回来，也留下 DNS rebinding 的空子。
    // 钉进去的条目在 curl 自己的 DNS 缓存里是永久的，所以每次先撤掉上一条，否则长期进程会越积越多，还会拿旧地址去连
    $resolve = [];
    if (isset($pinned)) { $resolve[] = '-'.$pinned; $pinned = null; }
    // URL 里直接写 IP 的不钉：本来就没有解析这一步，没有可乘之机，而且 host:port:addr 这个格式塞进一个 IPv6 字面量就分不出哪个冒号是分隔符了
    if ($ips && !empty(($parts = parse_url($url))['host']) && !filter_var($host = trim($parts['host'], '[]'), FILTER_VALIDATE_IP)) {
        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? '') == 'http' ? 80 : 443);
        // 地址侧的 IPv6 要套方括号，不然跟格式自己的冒号分不开
        foreach ($ips as $i => $ip) if (strpos($ip, ':') !== false) $ips[$i] = '['.$ip.']';
        $resolve[] = ($pinned = $host.':'.$port).':'.implode(',', $ips);
    }
    if ($resolve) $curl->setOpt(CURLOPT_RESOLVE, $resolve);
    // 跳转由 ActivityPub_GET 自己跟，POST 则完全不跟：curl 会把它降级成 GET
    $curl->setFollowLocation(false);
    // 只放行 http(s)，否则能把我们带到 file:// 之类的协议上
    $curl->setOpt(CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    $curl->setUserAgent('wxwClub '.$ver.'; '.$base);
    $curl->setHeader('Accept', 'application/activity+json');
    $curl->setHeader('Content-Type', 'application/activity+json');
    $curl->setHeader('Date', $date);
    // Curl 实例复用，先清掉上次请求独有的头，避免 POST 的 Digest 残留到之后的 GET
    foreach (array_diff(array_keys($last_head), array_keys($head)) as $k) $curl->unsetHeader($k);
    foreach ($head as $k => $v) $curl->setHeader($k, $v);
    $last_head = $head;
    if (isset($data)) $curl->post($url, $data); else $curl->get($url);
    Club_Log_Write('debug', 'curl', [isset($data) ? 'post' : 'get', strtolower($curl->responseHeaders['Status-Line'] ?? ''),
        preg_replace('#^https?://#i', '', $url)], ['header' => $curl->responseHeaders, 'result' => $curl->response, 'error' => $curl->error]);
    return $curl->error ? false : ($curl->response ?: true);
}

// HTTP Signature 真正覆盖的 authority 和 request-target。直接看原始 URL 里的 ?/#，避免不同 PHP 版本的 parse_url 对空 query 返回不同形状。
function ActivityPub_Signature_Fields($url) {
    $raw = (string)$url;
    if (($fragment = strpos($raw, '#')) !== false) $raw = substr($raw, 0, $fragment);
    $parts = parse_url($raw);
    if (!is_array($parts) || empty($parts['host'])) return false;
    if (isset($parts['user']) || isset($parts['pass'])) return false;
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') return false;
    $authority = $parts['host'];
    if (isset($parts['port']) && (int)$parts['port'] !== ($scheme === 'http' ? 80 : 443)) $authority .= ':'.(int)$parts['port'];
    $target = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
    if (($query = strpos($raw, '?')) !== false) $target .= substr($raw, $query);
    return ['authority' => $authority, 'target' => $target];
}

function ActivityPub_Signature($url, $club, $date, $digest = null) {
    global $db, $base;
    if (!($fields = ActivityPub_Signature_Fields($url))) return false;
    $post = isset($digest);
    $signed_string = "(request-target): ".($post ? 'post' : 'get')." ".$fields['target']."\nhost: ".$fields['authority']."\ndate: $date".($post ? "\ndigest: SHA-256=$digest" : '');
    $pdo = $db->prepare('select `private_key` from `clubs` where `name` = :name');
    $pdo->execute([':name' => $club]);
    if ($pdo = $pdo->fetch(PDO::FETCH_ASSOC)) {
        openssl_sign($signed_string, $signature, $pdo['private_key'], OPENSSL_ALGO_SHA256);
        return 'keyId="'.$base.'/club/'.$club.'#main-key'.'",algorithm="rsa-sha256",headers="(request-target) host date'.($post ? ' digest' : '').'",signature="'.base64_encode($signature).'"';
    } return false;
}

function ActivityPub_Verification($input = null, $pull = true) {
    global $db, $verify_signed, $verify_actor, $verify_retry, $fetch_retry;
    static $algos = ['rsa-sha256' => OPENSSL_ALGO_SHA256, 'hs2019' => OPENSSL_ALGO_SHA256, 'rsa-sha512' => OPENSSL_ALGO_SHA512];
    // 默认终局：下面的每一条头部检查，失败原因都在对端那份请求里，重投多少次都是同一个结果
    $verify_retry = false;
    if (empty($_SERVER['HTTP_SIGNATURE'])) return ActivityPub_Verify_Fail('no signature header');
    $signature = [];
    preg_match_all('/[,\s]*(.*?)="(.*?)"/', $_SERVER['HTTP_SIGNATURE'], $matches);
    foreach ($matches[1] as $k => $v) $signature[$v] = $matches[2][$k];
    if (empty($signature['keyId']) || empty($signature['signature']) || empty($signature['headers'])) return ActivityPub_Verify_Fail('malformed signature header');
    // algorithm 是对端给的，直接透传给 openssl 等于让对方挑摘要算法
    $algo = strtolower($signature['algorithm'] ?? 'rsa-sha256');
    if (!isset($algos[$algo])) return ActivityPub_Verify_Fail('unsupported algorithm: '.$algo);

    $post = strtolower($_SERVER['REQUEST_METHOD']) == 'post';
    // headers= 的顺序就是签名串的行顺序，(request-target) 不一定排在第一个
    $headers = explode(' ', strtolower($signature['headers']));
    if (!in_array('(request-target)', $headers)) return ActivityPub_Verify_Fail('(request-target) not signed');
    // date 或 (created) 必须签名并校验时效，否则签名可以被无限重放
    if (in_array('date', $headers)) {
        if (!ActivityPub_Date_Verify($_SERVER['HTTP_DATE'] ?? '')) return ActivityPub_Verify_Fail('date out of range: '.($_SERVER['HTTP_DATE'] ?? '-').' vs '.gmdate('D, d M Y H:i:s T'));
    } elseif (in_array('(created)', $headers)) {
        if (!ActivityPub_Date_Verify($signature['created'] ?? '')) return ActivityPub_Verify_Fail('(created) out of range: '.($signature['created'] ?? '-').' vs '.time());
    } else return ActivityPub_Verify_Fail('neither date nor (created) signed');
    if (in_array('(expires)', $headers) && ($signature['expires'] ?? PHP_INT_MAX) < time()) return ActivityPub_Verify_Fail('signature expired at '.$signature['expires']);
    // POST 不签 digest 的话请求体可以被任意替换
    if ($post && !in_array('digest', $headers)) return ActivityPub_Verify_Fail('digest not signed');
    if ($post && empty($_SERVER['HTTP_DIGEST'])) return ActivityPub_Verify_Fail('digest header missing');

    $lines = [];
    foreach ($headers as $header) {
        switch ($header) {
            case '(request-target)': $lines[] = $header.': '.strtolower($_SERVER['REQUEST_METHOD']).' '.$_SERVER['REQUEST_URI']; break;
            case '(created)': case '(expires)': $lines[] = $header.': '.($signature[trim($header, '()')] ?? ''); break;
            default:
                // Content-Type / Content-Length 在 $_SERVER 里没有 HTTP_ 前缀
                $key = strtoupper(str_replace('-', '_', $header));
                if (!in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) $key = 'HTTP_'.$key;
                $lines[] = $header.': '.($_SERVER[$key] ?? '');
        }
    } $verify_signed = implode("\n", $lines);

    // keyId 一般是 actor 后面挂个片段，去掉片段就是 actor；片段名不一定叫 main-key，少数实现写成路径，所以末尾的 /main-key 也一并去掉
    $actor = explode('#', $signature['keyId'])[0];
    if (substr($actor, -9) === '/main-key') $actor = substr($actor, 0, -9);
    $pdo = $db->prepare('select `public_key` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    if ($public_key = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
        $result = openssl_verify($verify_signed, base64_decode($signature['signature']), $public_key, $algos[$algo]);
        if ($result === 1) {
            if ($post && !ActivityPub_Digest_Verify($input)) return false;
            $verify_actor = $actor; return true;
        }
        // 0 是签名对不上，-1 / false 是 openssl 本身出错（多半是公钥坏了）
        ActivityPub_Verify_Fail($result === 0 ? 'signature mismatch' : 'openssl error: '.openssl_error_string());
    } else ActivityPub_Verify_Fail('unknown actor: '.$actor);

    // 可能是没见过的 actor，也可能是对方轮换了密钥，拉取一次后重试
    if ($pull && Club_Sync_Actor($actor)) return ActivityPub_Verification($input, false);
    // 走到这里是本站手上没有可用的当前公钥。只有刷新是因为解析不动、连不上、对端 5xx、冷却中这类会好的原因才没成，重投才有意义；
    // actor 指向内网、404、文档本身不合法那种刷多少遍都一样，和「拿着当前公钥仍然对不上」一起算终局 —— 投递侧不把验签失败当终局（本站的 ActivityPub_POST 和 Mastodon 的
    // response_error_unsalvageable? 都把 401 排除在外），给会重试的状态码就是让一条永远验不过的活动无限重投，还会算进对端整家实例的失败统计。不拉取的调用方（销号那条）没有自愈机会，同样终局
    $verify_retry = $pull && !empty($fetch_retry);
    return false;
}

// 签名只能证明「这个 keyId 的主人发的」。不比对 activity 的 actor 的话，任何一个公钥入过库的远端用户都能冒充别人删号、退关、撤帖
function ActivityPub_Verify_Actor($actor) {
    global $verify_actor;
    if (empty($verify_actor) || $verify_actor !== $actor) return ActivityPub_Verify_Fail('actor mismatch: '.$actor.' vs '.($verify_actor ?: '-'));
    return true;
}

function ActivityPub_Verify_Fail($reason) {
    global $verify_reason; $verify_reason = $reason; return false;
}

// 把对端给的语言标记归一到 src/i18n/ 下支持的语言，认不出返回 false。
// 文件名直接用 Mastodon 那套地区写法（它的 config/locales 和前端 locales 也是 zh-CN / zh-TW / zh-HK），这样内部 locale 就是对外发的标记，不用再转一道；script 写法留在下面当输入别名
function Club_I18n_Match($lang) {
    static $map = [
        'zh' => 'zh-CN', 'zh-hans' => 'zh-CN', 'zh-cn' => 'zh-CN', 'zh-sg' => 'zh-CN',
        'zh-hant' => 'zh-TW', 'zh-hant-tw' => 'zh-TW', 'zh-tw' => 'zh-TW',
        'zh-hant-hk' => 'zh-HK', 'zh-hk' => 'zh-HK', 'zh-mo' => 'zh-HK',
        'yue' => 'zh-HK', 'zh-yue' => 'zh-HK',
        'ja' => 'ja', 'en' => 'en'
    ];
    $lang = strtolower(str_replace('_', '-', trim((string)$lang)));
    // en-GB / ja-JP 这类带地区的标记回退到主语言
    return $map[$lang] ?? $map[explode('-', $lang)[0]] ?? false;
}

// Mastodon 一类实现用 contentMap 的键标记正文语言，取第一个能认出的
function Club_I18n_Detect($object) {
    if (empty($object['contentMap']) || !is_array($object['contentMap'])) return false;
    foreach (array_keys($object['contentMap']) as $lang) if ($match = Club_I18n_Match($lang)) return $match;
    return false;
}

// 认不出对方语言就用预设语言，预设语言也认不出才落到 en
function Club_I18n_Locale($lang) {
    global $config;
    return Club_I18n_Match($lang) ?: (Club_I18n_Match($config['node']['language'] ?? 'en') ?: 'en');
}

function Club_I18n($key, $locale, $vars = []) {
    global $config; static $cache = [];
    foreach (array_unique([$locale, Club_I18n_Locale(null), 'en']) as $try) {
        if (!isset($cache[$try])) {
            $file = APP_ROOT.'/src/i18n/'.$try.'.php';
            $cache[$try] = is_file($file) ? require($file) : [];
        }
        if (isset($cache[$try][$key])) {
            $text = $cache[$try][$key];
            foreach ($vars as $k => $v) $text = str_replace(':'.$k.':', $v, $text);
            return $text;
        }
    } return '';
}

// 启动时把 config.php 整个过一遍。缺一项现在的表现取决于它被怎么读：无遮拦的那几个是一串对不上号的 PHP warning 加一句 PDO 报错，有回退的则一声不吭按默认值跑几个小时，
// 最后从队列的异常反推回配置。两档正是照着这个区别划的 —— fatal 是本来就起不来的，把说不清楚的崩溃换成一句话；warning 是跑得起来但跑的不是配置里写的那套。
// 判据只看键本身，不碰数据库也不出网：它排在建连接之前，而配置错的时候连接八成也连不上。返回 [fatal, warning] 两个字符串列表
function Club_Config_Check() {
    global $config; $fatal = []; $warn = [];
    if (!is_array($config)) return [['config.php did not define $config as an array'], []];
    // 以下每一项都在代码里被无遮拦地读：$base 的拼接、PDO 的 DSN、date_default_timezone_set、Club_Create 里的 in_array
    if (!is_string($config['base'] ?? null) || $config['base'] === '') $fatal[] = 'config.base must be a non-empty host name';
    foreach (['host', 'database', 'username', 'password'] as $key) if (!is_string($config['mysql'][$key] ?? null)) $fatal[] = 'config.mysql.'.$key.' must be a string';
    // 时区不合法时 date_default_timezone_set 只发一条 warning 就退回 UTC，日志时间和对外的 published 会整体偏几个小时，而这两处谁都不会当场发现
    if (!is_string($tz = $config['node']['timezone'] ?? null) || !in_array($tz, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true))
        $fatal[] = 'config.node.timezone must be a valid timezone identifier';
    if (!is_array($config['club']['suspended-names'] ?? null)) $fatal[] = 'config.club.suspended-names must be an array, Club_Create passes it to in_array unguarded';
    // 缺扩展的报错发生在第一次真正用到它的地方：pdo_mysql 是启动，curl 和 openssl 要等到第一次出网或第一次建群，那时候看到的是一句 undefined function
    foreach (['pdo_mysql', 'curl', 'openssl', 'json'] as $ext) if (!extension_loaded($ext)) $fatal[] = 'ext-'.$ext.' is required but not loaded';

    if (!is_string($level = $config['node']['log-level'] ?? 'info') || !in_array(strtolower($level), ['silent', 'error', 'warning', 'info', 'debug'], true))
        $warn[] = 'config.node.log-level is not one of silent/error/warning/info/debug, falling back to info';
    if (!Club_I18n_Match($config['node']['language'] ?? 'en')) $warn[] = 'config.node.language is not a locale under src/i18n/, falling back to en';
    // 模板和 actor 文档无遮拦地读这几项，缺了不影响队列，但对端拉到的是一份带空字段的 actor
    foreach (['node' => ['name', 'description'], 'default' => ['avatar', 'banner', 'summary', 'nickname', 'infoname']] as $section => $keys)
        foreach ($keys as $key) if (!isset($config[$section][$key])) $warn[] = 'config.'.$section.'.'.$key.' is missing, pages and the actor document read it unguarded';
    foreach (['name', 'email'] as $key) if (!isset($config['node']['maintainer'][$key])) $warn[] = 'config.node.maintainer.'.$key.' is missing, the home page reads it unguarded';
    if (!isset($config['club']['open-registrations'])) $warn[] = 'config.club.open-registrations is missing, no new club can be created';
    if (Club_Config_Number($config['node']['log-retention'] ?? 30, 0) === false) $warn[] = 'config.node.log-retention must be a non-negative integer, 0 disables cleanup';
    // 一个投递进程都没有等于什么都发不出去。cli.php 起 master 时会再判一次并拒绝启动，这里只是把它提前到部署那一刻
    if (Club_Config_Number($config['worker']['delivery'] ?? 8, 1) === false) $warn[] = 'config.worker.delivery must be at least 1, nothing would ever be sent';
    if (Club_Config_Number($config['worker']['probe'] ?? 1, 0) === false) $warn[] = 'config.worker.probe must be a non-negative integer, 0 means blacklisted endpoints are never restored';
    // resolver 是唯一的解析出口，列表坏掉的表现是每一次投递都 local-dns：不记失败、不退避、每五分钟原地重投
    if (!is_array($resolvers = $config['dns']['resolver'] ?? []) || !$resolvers) $warn[] = 'config.dns.resolver is empty or not a list, falling back to the built-in defaults';
    else foreach (array_values($resolvers) as $i => $resolver) {
        if (!is_array($resolver) || !is_string($url = $resolver['url'] ?? null) || !preg_match('#^https://#i', $url)) $warn[] = 'config.dns.resolver['.$i.'].url must be an https URL';
        foreach (is_array($resolver['ip'] ?? []) ? $resolver['ip'] : [$resolver['ip'] ?? null] as $ip) if (isset($ip) && !filter_var($ip, FILTER_VALIDATE_IP))
            $warn[] = 'config.dns.resolver['.$i.'].ip contains something that is not an IP address: '.substr((string)$ip, 0, 60);
    }
    // 两个超时在 Club_Resolver_DoH 里被夹到 1 以上，配 0 的本意多半是「不限」，而 libcurl 的 0 正是永不超时，夹一下反而与本意相反
    foreach (['timeout', 'connect-timeout'] as $key) if (Club_Config_Number($config['dns'][$key] ?? 5, 1) === false)
        $warn[] = 'config.dns.'.$key.' must be at least 1 second, it is clamped to 1 at query time';
    if (Club_Config_Number($config['club']['relay-limit'] ?? 512, 1) === false) $warn[] = 'config.club.relay-limit must be at least 1 KB, edits and deletes would never be relayed';
    if (Club_Config_Number($config['club']['create-limit'] ?? 10, 0) === false) $warn[] = 'config.club.create-limit must be a non-negative integer, 0 disables the hourly cap';
    if (Club_Config_Number($config['notice']['limit'] ?? 20, 0) === false) $warn[] = 'config.notice.limit must be a non-negative integer';
    if (Club_Config_Number($config['notice']['retention'] ?? 30, 0) === false) $warn[] = 'config.notice.retention must be a non-negative integer, 0 disables cleanup';
    // 系统群组名要过得了建群那道正则，否则每次 Club_System() 都建不出来，私信、拉取 actor 和转发签名一起停摆
    if (!is_string($system = Club_System_Name()) || strlen($system) > 30 || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]+$/u', $system))
        $warn[] = 'config.club.system-name is not a valid club name, notices and actor fetches would have no signer';
    // 规则形状写错时 Club_Limit_Check 会静默跳过（hours/limit 无效）或按 user 计数（type 拼错），两种都让人以为限流生效了
    foreach (array_keys(is_array($limits = $config['club']['limits'] ?? []) ? $limits : []) as $club)
        foreach (Club_Limit_Rules($club) as $i => $rule) {
            if (!in_array(strtolower(is_string($t = $rule['type'] ?? null) ? $t : ''), ['user', 'club', 'site', 'dupl'], true))
                $warn[] = 'config.club.limits.'.$club.'['.$i.'].type is not one of user/club/site/dupl, it counts per user';
            if (Club_Config_Number($rule['hours'] ?? 0, 1) === false || Club_Config_Number($rule['limit'] ?? 0, 1) === false)
                $warn[] = 'config.club.limits.'.$club.'['.$i.'] needs hours and limit to be at least 1, the rule is skipped';
        }
    return [$fatal, $warn];
}

// 配置里的数值项：只接受整数或整数字符串，且不小于下限。true / '10 条' / 空数组这类都会被 (int) 悄悄变成一个能用的数
function Club_Config_Number($value, $min) {
    if (!is_int($value) && (!is_string($value) || !preg_match('/^-?[0-9]+$/D', $value))) return false;
    return (int)$value >= $min ? (int)$value : false;
}

// 建库连接。worker 开多进程时，每个子进程 fork 完都要自己调一次重新建：fork 继承的是父进程那条连接的 socket，而 last_insert_id() 和会话隔离级别都是连接级状态，共用一条就是两个子进程互相串。
// 持久连接只对 fpm 有意义（进程复用），CLI 下进程活着连接就活着，而且持久连接的池子也会被 fork 继承，子进程再 new PDO 会直接拿回父进程那条
function Club_DB_Connect() {
    global $db, $config;
    $options = [PDO::ATTR_PERSISTENT => PHP_SAPI != 'cli', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    // worker 全是短事务，不依赖 RR 快照，而 RC 不留间隙锁、UPDATE 扫到的不匹配行当场放锁，几十个进程同时改 endpoints、分批删 queues 才不会互相咬住，所以直接定在连接上。
    // web 走持久连接，会话级设置会跨请求留下来，那边的隔离级别由 Club_DB_Transaction 逐事务设。init command 不能给空字符串：mysqlnd 会拿它当一条查询发出去，连上就报 Query was empty
    if (PHP_SAPI == 'cli') $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'set session transaction isolation level read committed';
    return $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'].';charset=utf8mb4', $config['mysql']['username'], $config['mysql']['password'], $options);
}

// 库里当前的结构版本。meta 表本身是这套版本机制引入的，它不在就说明这个库停留在引入之前，一律当 0 处理，由 worker 从头合并
function Club_DB_Version($set = null) {
    global $db;
    if (isset($set)) {
        $pdo = $db->prepare('insert into `meta`(`name`,`value`) values (\'schema\', :value) on duplicate key update `value` = :value');
        $pdo->execute([':value' => (int)$set]);
        return (int)$set;
    }
    try {
        $pdo = $db->query('select `value` from `meta` where `name` = \'schema\'');
        $value = $pdo->fetch(PDO::FETCH_COLUMN, 0);
        if ($value === false) return 0;
        $raw = (string)$value;
        if (!preg_match('/^[0-9]+$/D', $raw)) throw new UnexpectedValueException('invalid database schema version: '.substr($raw, 0, 100));
        $number = ltrim($raw, '0');
        if ($number === '') return 0;
        $max = (string)PHP_INT_MAX;
        if (strlen($number) > strlen($max) || (strlen($number) === strlen($max) && strcmp($number, $max) > 0))
            throw new UnexpectedValueException('database schema version is out of range: '.substr($raw, 0, 100));
        return (int)$number;
    } catch (PDOException $e) {
        // 只有版本机制尚未建立才是历史库；连接、权限或表损坏必须原样上抛，否则 worker 会把基础设施故障误判成 schema 0 并尝试迁移。
        if ($e->getCode() === '42S02' || (int)($e->errorInfo[1] ?? 0) === 1146) return 0;
        throw $e;
    }
}

// 死锁（1213）和锁等待超时（1205）重来一次通常就过去了，连成片才是真出事。1213 服务端已经把整笔事务回滚了，1205 只回滚当前语句，后者不显式 rollback 就会带着半截事务往下走；
// inTransaction 为假时再 rollback 只会抛一个盖住原因的异常。只包住数据库这一段：已经发出去的 HTTP 永远不能跟着重试再发一次
function Club_DB_Retry($what, $run, $attempts = 3) {
    global $db;
    for ($attempt = 1; ; $attempt++) try {
        return $run();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        if ($attempt >= $attempts || !in_array((int)($e->errorInfo[1] ?? 0), [1213, 1205])) throw $e;
        Club_Stat('db_retries');
        Club_Log_Event('debug', 'retrying after database contention', ['at' => $what, 'attempt' => $attempt, 'error' => $e->getMessage()]);
        usleep(mt_rand(20000, 120000));
    }
}

// 入站本地状态和它派生的出站队列共用这条短事务边界。已有事务时只加入，最外层才负责重试和提交；调用方必须在进来之前完成 actor 拉取、DNS 和 HTTP。
function Club_DB_Transaction($what, $run) {
    global $db;
    if ($db->inTransaction()) return $run();
    return Club_DB_Retry($what, function () use ($db, $run) {
        $db->exec('set transaction isolation level read committed');
        $db->beginTransaction();
        try {
            $result = $run();
            if (!$db->commit()) throw new PDOException($what.' commit returned false');
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollback();
            throw $e;
        }
    });
}

// 当前级别是否包含 $level。false 等同 silent，其他无法识别的取值按默认的 info 处理
function Club_Log_Level($level) {
    global $config; static $rank = ['silent' => 0, 'error' => 1, 'warning' => 2, 'info' => 3, 'debug' => 4];
    $set = $config['node']['log-level'] ?? 'info';
    if ($set === false) $set = 'silent';
    elseif (!is_string($set) || !isset($rank[$set = strtolower($set)])) $set = 'info';
    return $rank[$set] >= $rank[$level];
}

// 日志目录一律按 APP_ROOT 建：worker 的工作目录不一定是项目根目录，用相对路径会把目录建在别处，而写日志用的是绝对路径，等于白建
function Club_Log_Dir($dir = '') {
    // 父目录单独建：用 mkdir 的递归模式的话，中间层 logs/ 是 mkdir 内部建的，拿不到下面那次 chmod，会停在被 umask 削过的权限上
    if ($dir !== '' && !Club_Log_Dir()) return false;
    $path = APP_ROOT.'/logs'.($dir === '' ? '' : '/'.$dir);
    // web 和 worker 通常不是同一个用户，谁建的目录另一方都要能往里写、能删里面的文件（unlink 看的是目录权限，不是文件权限）
    if (!is_dir($path)) {
        // 并发请求可能同时建同一个目录，mkdir 失败后再确认一次。留着递归是为了 APP_ROOT 本身还不存在的情况，logs/ 这一层已经由上面那次调用单独建过
        if (!@mkdir($path, 0777, true) && !is_dir($path)) return false;
        // mkdir 的 mode 一样会被 umask 削掉，只能补一次 chmod
        @chmod($path, 0777);
    // 已经存在的目录也校一次：不是这次建的，权限未必够，跨用户写入前统一校正
    } elseif ((fileperms($path) & 0777) !== 0777) @chmod($path, 0777);
    return $path;
}

// 日志文件的实际落盘。同样是跨用户的问题：logs/event/ 和 logs/error/ 按天追加，web 用户先建出 0644 的文件后 worker 用户连 append 都会失败，比删不掉更早发作
function Club_Log_Put($file, $data, $flags = 0) {
    $new = !is_file($file);
    if (@file_put_contents($file, $data, $flags) === false) return false;
    // 已存在的也要校一次，不能假设「存在就说明建的时候 chmod 过了」：logs/error/ 下的文件是 PHP 引擎自己建的，从来没经过这里。
    // chmod 只有属主能调，不是自己的文件这一步会静默失败，但那时对方多半已经放开了
    if ($new || (fileperms($file) & 0777) !== 0666) @chmod($file, 0666);
    return true;
}

// 文件名片段清洗。片段来源里 status-line、url、webfinger 的 resource 都是对端可控的：路径分隔符换成形近的 Ⳇ 保留可读性，空白并成 _，控制字符和 glob 元字符去掉（去重时要拿基名当 glob 模式用）。
// 不加 u 修饰符，对端发来非法 UTF-8 时按字节处理才不会整个变空
function Club_Log_Slug($part) {
    $part = str_replace(['/', '\\'], 'Ⳇ', (string)$part);
    return preg_replace(['/\s+/', '/[\x00-\x1f\x7f*?\[\]]/'], ['_', ''], $part);
}

// 生成一组日志文件共用的基名，同一事件的多个文件靠它配对
function Club_Log_Name($dir, $parts) {
    if (!Club_Log_Level('error') || !($path = Club_Log_Dir($dir))) return '';
    // 时分秒之间用 - 不用 :，冒号在 NTFS 上是非法字符，写文件会直接失败
    $name = date('Y-m-d_H-i-s');
    foreach (to_array($parts) as $part) if (($part = Club_Log_Slug($part)) !== '') $name .= '_'.$part;
    // 文件名上限 255 字节，url 和 resource 能轻松撑爆，留出后缀和序号的余量
    $name = $base = substr($name, 0, 180);
    // 时间戳只到秒，同一秒的同名事件会互相覆盖（撤回提醒成批发出，必然撞），占用了就往后排
    for ($i = 1; $i < 100 && glob($path.'/'.$name.'*'); $i++) $name = $base.'-'.$i;
    return $name;
}

// 写日志的唯一入口：级别不够直接跳过，目录按需建。$name 传数组表示这条日志独占一个基名，传字符串则是 Club_Log_Name 的结果加后缀
function Club_Log_Write($level, $dir, $name, $data, $ext = 'json') {
    if (!Club_Log_Level($level)) return false;
    if (is_array($name)) $name = Club_Log_Name($dir, $name);
    if ($name === '' || !($path = Club_Log_Dir($dir))) return false;
    return Club_Log_Put($path.'/'.$name.($ext === '' ? '' : '.'.$ext), is_string($data) ? $data : Club_Json_Encode($data));
}

// PHP 自己写的 error log 的目标文件。worker 是长期进程，不会重新走 bootstrap：跨天后它还指着启动那天的文件，等 rotate 把那个删掉，引擎会自己重建一个，权限也就丢了
function Club_Log_Error_Path() {
    static $last = '';
    if (!Club_Log_Level('error')) return;
    $file = APP_ROOT.'/logs/error/'.date('Y-m-d').'.log';
    // 日期没变且文件还在就不用重复摸盘（unlink 会刷掉 stat 缓存，rotate 删了这里能立刻看到）
    if ($file === $last && is_file($file)) return;
    if (!Club_Log_Dir('error')) return;
    // 预建一次：之后这个文件由 PHP 自己写，只有现在这一下能管到它的权限
    Club_Log_Put($file, '', FILE_APPEND);
    ini_set('error_log', $last = $file);
}

// 当前这次处理的关联标记，值就是 logs/inbox/ 或 logs/outbox/ 下那组文件的基名。event 里每行都带上它，看到可疑的一行可以直接 ls logs/inbox/<标记>* 把报文捞出来。
// 同一秒进来好几条活动是常态，只靠 event 的时间戳对不上具体是哪一条
function Club_Log_Ref($ref = null) {
    static $current = '';
    if (isset($ref)) $current = $ref;
    return $current;
}

// 低频事件写成按天追加的一个文件：拉黑、建群、销号这类一行就说清楚的事，按事件切成一堆 JSON 文件反而难查。跟 logs/error/ 分开，那边只留 PHP 引擎自己的报错。
// 汇总和心跳传 $dir = 'stat' 写到 logs/stat/：那几行按窗口定期输出，条数由进程数决定，混在 event 里会把真正发生过的事顶出屏幕
function Club_Log_Event($level, $message, $context = [], $dir = 'event') {
    if (!Club_Log_Level($level) || !($path = Club_Log_Dir($dir))) return false;
    if ($ref = Club_Log_Ref()) $message = $ref.' '.$message;
    if ($context) $message .= ' '.Club_Json_Encode($context);
    // 一条事件一行，消息里的换行要压掉，否则 grep 出来只有半句
    $written = Club_Log_Put($path.'/'.date('Y-m-d').'.log', date('[Y-m-d H:i:s] ').strtoupper($level).' '.preg_replace('/\s+/', ' ', $message)."\n", FILE_APPEND);
    // 只算真正落盘的 worker 事件；web/master 没有 slot，不能污染某个 worker 的窗口。Flush 自己只写 info，所以这里累计 warning/error 不会递归制造新的 material。
    if ($written && Club_Worker_Slot() !== null && in_array($level, ['warning', 'error'], true)) Club_Stat($level === 'warning' ? 'log_warnings' : 'log_errors');
    return $written;
}

// worker 的输出既要实时给人看，也要留档，两边共用一个入口。上下文照着 Club_Log_Event 的样子拼在消息后面，终端和 event 日志里是同一行；
// 多进程模式下几个进程的输出混在一起，靠这里的 pid 才分得清哪句是谁说的
function Club_Log_Console($level, $message, $context = []) {
    if (PHP_SAPI == 'cli') echo date('[Y-m-d H:i:s]').' '.$message.($context ? ' '.Club_Json_Encode($context) : ''), "\n";
    Club_Log_Event($level, $message, $context);
}

// 本进程是哪个队列，FPM 里是 null。master 补进程之后 pid 会变，按「类型.序号」才认得出重启的是同一个位置，日志也能按类型分开看
function Club_Worker_Slot($slot = null) {
    static $current = null;
    if (isset($slot)) $current = $slot;
    return $current;
}

// 默认 info 级别下逐条 debug 是关掉的，性能只能靠进程自己在内存里攒。传 null 表示取走并清空，汇总时用
function Club_Stat($key, $value = 1) {
    static $data = [];
    if (!isset($key)) { $out = $data; $data = []; return $out; }
    if (!isset($data[$key])) $data[$key] = 0;
    $data[$key] += $value;
    return $data[$key];
}

// 分位数要原始样本，计数器攒不出来。一个窗口最多留 1000 个：够算 p99，也不会让一个卡住的进程把内存吃光
function Club_Stat_Sample($key, $ms = null) {
    static $data = [];
    if (!isset($key)) { $out = $data; $data = []; return $out; }
    if (!isset($data[$key])) $data[$key] = [];
    if (count($data[$key]) < 1000) $data[$key][] = $ms;
    return true;
}

// 窗口内的峰值，lag 这类瞬时量取最大值才有意义
function Club_Stat_Max($key, $value = 0) {
    static $data = [];
    if (!isset($key)) { $out = $data; $data = []; return $out; }
    if (!isset($data[$key]) || $value > $data[$key]) $data[$key] = $value;
    return $data[$key];
}

function Club_Stat_Percentile($samples) {
    sort($samples); $count = count($samples); $out = [];
    foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $name => $rank) $out[$name] = round($samples[min($count - 1, (int)ceil($rank * $count) - 1)], 1);
    $out['max'] = round($samples[$count - 1], 1);
    return $out;
}

// 每 60 秒一次的结构化汇总。空转不输出：32 个进程每分钟 32 行全零汇总，既看不出问题，还会把真正的事件冲出滚动窗口。
// 纯 claim miss 不算发生过事情，但那样的进程 15 分钟要留一条心跳 —— 没有任何记录的槽位才是要告警的
function Club_Stat_Flush($force = false, $window = 60, $heartbeat = 900) {
    static $opened = 0, $checked = 0, $seen = 0, $progress = 0, $jitter = null;
    static $pending = [], $pending_samples = [], $pending_gauges = [];
    $now = time(); $slot = Club_Worker_Slot();
    // 同一秒 32 行日志谁也看不清，各自错开几秒
    if (!isset($jitter)) $jitter = mt_rand(0, 20);
    if (!$opened) {
        $opened = $checked = $seen = $progress = $now;
        // 正常启动先建窗口；停机钩子若抢在首轮 flush 前到达，force 仍要把已有计数取走。
        if (!$force) return false;
    }
    if (!$force && $now - $checked < $window + $jitter) return false;
    $counters = Club_Stat(null); $samples = Club_Stat_Sample(null); $gauges = Club_Stat_Max(null);
    $checked = $now;
    foreach ($counters as $key => $value) {
        if (!isset($pending[$key])) $pending[$key] = 0;
        $pending[$key] += $value;
    }
    foreach ($samples as $key => $values) {
        if (!isset($pending_samples[$key])) $pending_samples[$key] = [];
        foreach ($values as $value) if (count($pending_samples[$key]) < 1000) $pending_samples[$key][] = $value;
    }
    foreach ($gauges as $key => $value) if (!isset($pending_gauges[$key]) || $value > $pending_gauges[$key]) $pending_gauges[$key] = $value;
    $material = 0;
    foreach (['endpoint_done', 'endpoint_claims', 'probe_claims', 'maintenance_runs', 'http_requests', 'dns_queries',
        'db_retries', 'stale_tokens', 'stale_queues', 'renew_failed', 'log_warnings', 'log_errors'] as $key) $material += $pending[$key] ?? 0;
    if (!$material) {
        if ($now - $seen < $heartbeat && (!$force || (!$pending && !$pending_samples && !$pending_gauges))) return false;
        $elapsed = max(1, $now - $opened); $opened = $seen = $now;
        Club_Log_Event('info', 'worker heartbeat', ['slot' => $slot, 'pid' => getmypid(), 'window_s' => $elapsed, 'last_progress_at' => $progress, 'idle_ms' => (int)($pending['idle_ms'] ?? 0),
            'endpoint_claim_attempts' => (int)($pending['endpoint_claim_attempts'] ?? 0), 'endpoint_claim_misses' => (int)($pending['endpoint_misses'] ?? 0),
            // 一直在抢、一直没抢到的槽位跟真空闲的槽位在这里长得一模一样，而前者说明并发开过头了，只有这一个数能把两者分开
            'endpoint_claim_races' => (int)($pending['endpoint_claim_races'] ?? 0), 'scheduler_db_ops' => (int)($pending['scheduler_db_ops'] ?? 0)], 'stat');
        $pending = []; $pending_samples = []; $pending_gauges = [];
        return true;
    }
    $elapsed = max(1, $now - $opened); $opened = $seen = $progress = $now;
    $attempts = (int)($pending['endpoint_claim_attempts'] ?? 0);
    $summary = ['slot' => $slot, 'pid' => getmypid(), 'window_s' => $elapsed,
        'endpoint_claim_attempts' => $attempts,
        // 命中率仍然是「拿到租约的比例」，跨版本可比。没拿到的两种原因差别很大，所以 races 单列：hit 低而 races 高是并发开过头，hit 低而 races 是 0 才是没活干
        'endpoint_claim_hit' => $attempts ? round(1 - (($pending['endpoint_misses'] ?? 0) + ($pending['endpoint_claim_races'] ?? 0)) / $attempts, 3) : 0,
        'scheduler_db_ops' => (int)($pending['scheduler_db_ops'] ?? 0)];
    foreach ($pending_samples as $key => $values) $summary[$key] = Club_Stat_Percentile($values);
    foreach ($pending_gauges as $key => $value) $summary[$key] = $value;
    foreach ($pending as $key => $value) if (!isset($summary[$key]) && $key != 'endpoint_misses') $summary[$key] = $value;
    $summary['busy_ratio'] = $elapsed ? round(max(0, min(1, 1 - ($pending['idle_ms'] ?? 0) / ($elapsed * 1000))), 3) : 0;
    Club_Log_Event('info', 'worker summary', $summary, 'stat');
    $pending = []; $pending_samples = []; $pending_gauges = [];
    return true;
}

// FPM 也会走 resolver，跨进程的 DNS 争用只能靠两边各自的汇总在日志侧拼起来。请求里一次 DNS 都没发生时不写，否则每个页面请求都要多一行
function Club_Stat_Request() {
    $counters = Club_Stat(null); $samples = Club_Stat_Sample(null);
    if (!$counters) return false;
    $dns = 0;
    foreach ($counters as $key => $value) if (strpos($key, 'dns_') === 0) $dns += $value;
    if (!$dns) return false;
    foreach ($samples as $key => $values) $counters[$key] = Club_Stat_Percentile($values);
    Club_Log_Event('info', 'request summary', ['sapi' => PHP_SAPI, 'pid' => getmypid()] + $counters, 'stat');
    return true;
}

// master 启动时把上一次运行留下的当天流式日志挪到 .001 .002，tail 和排查不用再从半截文件里找边界。
// 只在 fork 之前调用一次：worker 各自重启（master 补进程、超内存自杀）不是重启，跟着挪的话一天下来全是几分钟一份的碎片。
// 边界是尽力而为，不是严格分段：rename 期间 FPM 和上一批还没退干净的 worker 仍在写，
// 一次 Club_Log_Put 若在改名前解析完路径、改名后才落笔，这一行就进了 .001（POSIX 上 rename 不动 inode）。窗口是一次 open-write-close，亚毫秒级，跨过它的顶多是几行。
// 要做到一行不错就得给每次写日志加一把跨进程锁 —— 为了「这一行属于哪次运行」把每条日志都变成一次加锁，代价远大于收益。真要精确定位边界，看 master started 那一行的时间戳
// 只挪 event/stat：这两个由 Club_Log_Put 每次开-写-关，rename 之后还在写的 worker 和 FPM 会在原路径重建；logs/error/ 是 PHP 引擎自己写的，管不到它什么时候重开。
// 序号跟 Club_Log_Name 一样往后排而不是照 logrotate 级联：一次 rename 不用把 .001..N 全推一遍，序号大的就是晚的，跟按天读日志的方向一致。
// crash loop 里挤掉的也是最新那份而不是最早那份 —— 崩溃循环里第一次的现场最值钱。
// rename 保留 mtime，保留期照旧由 Club_Log_Rotate 按天数删
function Club_Log_Shift($dirs = ['event', 'stat']) {
    foreach ($dirs as $dir) {
        // 目录建不出来时下面这行也落不了盘，但 master 是 CLI，Club_Log_Console 至少还会 echo 到 stdout（容器里就是 docker logs）
        if (!($path = Club_Log_Dir($dir))) { Club_Log_Console('warning', 'log shift skipped, cannot create directory', ['dir' => $dir]); continue; }
        $file = $path.'/'.date('Y-m-d').'.log';
        // 第一次跑和跨天都走这里，是常态，不值一条 warning。空文件挪了也只是留一串空壳
        if (!is_file($file) || !filesize($file)) { Club_Log_Event('debug', 'log shift skipped, nothing to move', ['file' => $file]); continue; }
        // 序号补零对齐三位：不补的话 ls 和 glob 的字典序从第十次切分起就不是时间序了（.1 .10 .100 .11 .2 .9），采集器按 *.log.* 收上去正好把崩溃循环打乱。
        // 同一天重启一百次之后不再往后排，最后一个反复被覆盖
        for ($i = 1; $i < 100 && is_file($file.sprintf('.%03d', $i)); $i++);
        error_clear_last();
        if (@rename($file, $target = $file.sprintf('.%03d', $i))) continue;
        // 挪不动只是本次运行接着写上一次的尾巴，不该拦住启动；但没有这一行的话，现场看到的是「切分功能像是没生效」而不是原因。warning 正好落在没挪走的那个文件里
        Club_Log_Console('warning', 'log shift failed, still appending to the previous file', ['file' => $file, 'target' => $target, 'error' => error_get_last()['message'] ?? '']);
    }
}

// logs/ 按请求写文件，长期跑会占满 inode，由 worker 空闲时清理
function Club_Log_Rotate($days) {
    if ($days < 1 || !is_dir($root = APP_ROOT.'/logs')) return;
    $expire = time() - $days * 86400;
    foreach (glob($root.'/*', GLOB_ONLYDIR) as $dir) foreach (glob($dir.'/*') as $file) if (is_file($file) && filemtime($file) < $expire) unlink($file);
}

function ActivityPub_Date_Verify($date, $skew = 300) {
    if (empty($date)) return false;
    // Date 头是 HTTP 日期，(created) 是 unix 时间戳
    $time = ctype_digit((string)$date) ? (int)$date : strtotime($date);
    return $time && abs(time() - $time) <= $skew;
}

function ActivityPub_Digest_Verify($input) {
    if (!preg_match('/([A-Za-z0-9-]+)\s*=\s*([A-Za-z0-9+\/=]+)/', $_SERVER['HTTP_DIGEST'], $matches)) return ActivityPub_Verify_Fail('malformed digest header');
    $algo = strtolower(str_replace('-', '', $matches[1]));
    if (!in_array($algo, ['sha256', 'sha512'])) return ActivityPub_Verify_Fail('unsupported digest algorithm: '.$matches[1]);
    if (!hash_equals(hash($algo, (string)$input, 1), base64_decode($matches[2]))) return ActivityPub_Verify_Fail('digest mismatch, body length '.strlen((string)$input));
    return true;
}

function Club_Exist($club) {
    global $db, $config, $club_reason; $club_reason = null;
    if (strlen($club) > 30 || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]+$/u', $club)) return Club_Exist_Fail('invalid name');
    $pdo = $db->prepare('select `name` from `clubs` where `name` = :name'); $pdo->execute([':name' => $club]);
    // 系统群组要能被远端解析（对端验签时要拉 actor），但不能被外部抢注
    if ($pdo = $pdo->fetch(PDO::FETCH_COLUMN, 0)) return $pdo;
    if (Club_System_Name($club)) return Club_Exist_Fail('reserved name');
    return $config['club']['open-registrations'] ? Club_Create($club) : Club_Exist_Fail('registration closed');
}

function Club_Exist_Fail($reason) {
    global $club_reason; $club_reason = $reason; return false;
}

// 传名字则判断它是不是系统群组，不传则返回系统群组名
function Club_System_Name($club = null) {
    global $config; $name = $config['club']['system-name'] ?? 'system';
    return isset($club) ? strtolower($club) === strtolower($name) : $name;
}

// 发私信提醒、对外拉取、给透传的转发签名都用它，不开放注册、不进目录、不接受关注
function Club_System() {
    global $db; $name = Club_System_Name();
    $pdo = $db->prepare('select `name` from `clubs` where `name` = :name');
    $pdo->execute([':name' => $name]);
    return $pdo->fetch(PDO::FETCH_COLUMN, 0) ?: Club_Create($name, true);
}

function Club_Create($club, $system = false) {
    global $db, $config;
    // 系统群组由内部创建，不受禁用名单和限速约束
    if (!$system) {
        if (in_array(strtolower($club), $config['club']['suspended-names'])) {
            Club_Log_Event('info', 'club create rejected, suspended name: '.$club);
            return Club_Exist_Fail('suspended name');
        }
        // 建群入口无鉴权且每次要生成一对 2048 位密钥，不封顶的话循环请求随机名字就能打满 CPU
        if ($limit = $config['club']['create-limit'] ?? 10) {
            $pdo = $db->prepare('select count(cid) from `clubs` where `timestamp` >= :timestamp');
            $pdo->execute([':timestamp' => time() - 3600]);
            if ($pdo->fetch(PDO::FETCH_COLUMN, 0) >= $limit) {
                // 限速本身是正常防护，但连续触发通常意味着有人在刷，值得看见
                Club_Log_Event('warning', 'club create rate limited: '.$club.', '.$limit.'/hour');
                return Club_Exist_Fail('create limited');
            }
        }
    }
    $key = openssl_pkey_new(['digest_alg' => 'sha512', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$key || !openssl_pkey_export($key, $priv_key)) {
        Club_Log_Event('error', 'club keygen failed: '.$club.', '.openssl_error_string());
        return Club_Exist_Fail('keygen failed');
    }
    $detail = openssl_pkey_get_details($key);
    $pdo = $db->prepare('insert into `clubs`(`name`,`public_key`,`private_key`,`timestamp`) values(:name, :public, :private, :timestamp)');
    try { $pdo->execute([':name' => $club, ':public' => $detail['key'], ':private' => $priv_key, ':timestamp' => time()]); }
    catch (PDOException $e) {
        // 只有并发建群撞唯一键能靠下面重查收敛；连接、权限、死锁等必须原样上抛。
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
    }
    $pdo = $db->prepare('select `name` from `clubs` where `name` = :name');
    $pdo->execute([':name' => $club]);
    return $pdo->fetch(PDO::FETCH_COLUMN, 0);
}

function Club_Get_Actor($actor) {
    global $db, $fetch_retry; $pdo = $db->prepare('select `uid`,`name`,`inbox`,`shared_inbox` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    $row = $pdo->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $inbox = Club_Endpoint_Normalize($row['inbox']);
        $shared = Club_Endpoint_Normalize($row['shared_inbox']);
        if ($inbox !== false && $shared !== false && $inbox === $row['inbox'] && $shared === $row['shared_inbox']) return $row;
        Club_Log_Event('warning', 'cached actor has unusable endpoint, refreshing', ['actor' => $actor, 'inbox' => $row['inbox'], 'shared_inbox' => $row['shared_inbox']]);
    }
    // 出站拉取不能跨数据库事务；事务内的调用者只能使用已经验证过的缓存行。
    if ($db->inTransaction()) {
        Club_Log_Event('warning', 'actor refresh skipped inside database transaction', ['actor' => $actor]);
        // 这次压根没拉，$fetch_retry 还是上一次拉取留下的值，得自己写：什么都没试过，对调用方就只能是「稍后再来」
        $fetch_retry = true; return false;
    }
    return Club_Fetch_Actor($actor);
}

// 拉取远端 actor 写入本地缓存，已存在则更新（对方可能轮换密钥或迁移 inbox）。
// users 是全站共用的一份，这行属于哪个群组无从谈起，签名统一用系统群组：同一行的建立和刷新由不同群组签本来就不一致，
// 而随手拿投稿命中的某个群组来签，等于让对端为一次首访去拉一个它没见过、还可能单独封过的 actor
function Club_Fetch_Actor($actor) {
    global $db, $fetch_retry;
    if (!($club = Club_System())) {
        Club_Log_Event('warning', 'fetch actor skipped, no system club', ['actor' => $actor]);
        // 缺系统群组是本站自己的毛病，建出来就好了，不能让对端替我们把活动丢掉
        $fetch_retry = true; return false;
    }
    $jsonld = json_decode(ActivityPub_GET($actor, $club), 1);
    if (empty($jsonld['id']) || $jsonld['id'] != $actor || empty($jsonld['inbox'])) {
        Club_Log_Event('warning', 'fetch actor failed: '.$actor);
        return false;
    }
    // inbox 是这个 actor 之后所有投递的 key，规范化不了就没有能落库的目标：存原样的话，同一个 inbox 换个大小写写法就会变成两条 endpoint、两套退避
    if (($inbox = Club_Endpoint_Require($jsonld['inbox'], ['actor' => $actor])) === false) {
        Club_Log_Event('warning', 'fetch actor failed, inbox is not a usable endpoint', ['actor' => $actor, 'inbox' => $jsonld['inbox']]);
        return false;
    }
    // sharedInbox 坏了还能退回个人 inbox，不必连这个 actor 一起丢
    $shared = $jsonld['endpoints']['sharedInbox'] ?? null;
    if (!isset($shared) || ($shared = Club_Endpoint_Require($shared, ['actor' => $actor])) === false) $shared = $inbox;
    $data = [':name' => ($jsonld['preferredUsername'] ?? '').'@'.parse_url($jsonld['id'], PHP_URL_HOST), ':inbox' => $inbox,
        ':public_key' => $jsonld['publicKey']['publicKeyPem'] ?? '', ':shared_inbox' => $shared, ':actor' => $jsonld['id'], ':timestamp' => time()];
    $pdo = $db->prepare('select `uid` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    if ($pdo->fetch(PDO::FETCH_COLUMN, 0)) $pdo = $db->prepare('update `users` set `name` = :name, `inbox` = :inbox,'.
        ' `public_key` = :public_key, `shared_inbox` = :shared_inbox, `refresh` = :timestamp where `actor` = :actor');
    else $pdo = $db->prepare('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`,`refresh`)'.
        ' values (:name, :actor, :inbox, :public_key, :shared_inbox, :timestamp, :timestamp)');
    try { $pdo->execute($data); }
    catch (PDOException $e) {
        // 并发首次缓存同一 actor 可重查收敛；其他写故障不能伪装成 actor 不存在。
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
    }
    $pdo = $db->prepare('select `uid`,`name`,`inbox`,`shared_inbox` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    return $pdo->fetch(PDO::FETCH_ASSOC);
}

// 本地缓存里有没有这个 actor。只查不拉，用来挡掉与本站无关的广播
function Club_Has_Actor($actor) {
    global $db; $pdo = $db->prepare('select `uid` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    return (bool)$pdo->fetch(PDO::FETCH_COLUMN, 0);
}

// 验签失败时刷新公钥，带冷却时间，防止伪造签名把本站当外连放大器
function Club_Sync_Actor($actor, $cooldown = 3600) {
    global $db, $fetch_retry; $pdo = $db->prepare('select `refresh` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    $refresh = $pdo->fetch(PDO::FETCH_COLUMN, 0);
    // 冷却中就不再拉。验签失败又刷不了公钥时，这行是唯一的解释
    if ($refresh !== false && $refresh > time() - $cooldown) {
        Club_Log_Event('debug', 'actor refresh on cooldown', ['actor' => $actor, 'retry in' => ($refresh + $cooldown - time()).'s']);
        // 冷却结束之后这次刷新照样会发生，对端过一会儿重投就是了
        $fetch_retry = true; return false;
    }
    return Club_Fetch_Actor($actor);
}

// 所有远端 inbox 进 users、queues、endpoints、blacklist 之前的唯一入口。规范化结果既是数据库主键，也是真正交给 cURL 的 URL，两者必须是同一个字符串。
// 只合并协议上必然等价的写法：scheme 和域名的大小写、默认端口、IPv6 压缩、空 path。path 和 query 一个字节都不动 —— 反向代理完全可以让 /Inbox 和 /inbox 落到两个应用，猜错了就是把两家的投递串到一起
function Club_Endpoint_Normalize($url) {
    if (!is_string($url) || $url === '') return false;
    // 非 ASCII 可打印字符直接拒绝：IDN 和 UTF-8 path 这一版不做转换，硬塞进 ascii 列只会被 MySQL 截断或报错，不如在入口挡掉。fragment 即使为空也不能进 endpoint；
    // PHP 7.3/8.x 对尾随 # 的 parse_url 结果不同，所以必须从原始 URL 判断
    if (preg_match('/[^\x21-\x7e]/', $url) || strpos($url, '#') !== false || !($parts = parse_url($url))) return false;
    if (!isset($parts['scheme'], $parts['host'])) return false;
    // userinfo 能把 curl 带去另一个 host，fragment 根本不会发给服务端，两者都不该出现在一个 inbox 里，出现了就是这个 URL 本身有问题
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    if (($scheme = strtolower($parts['scheme'])) !== 'http' && $scheme !== 'https') return false;
    $host = $parts['host'];
    if (substr($host, 0, 1) === '[') {
        // IPv6 字面量的写法太多，转成二进制再转回来才是唯一表示
        if (substr($host, -1) !== ']' || !($bin = @inet_pton(substr($host, 1, -1))) || strlen($bin) !== 16) return false;
        $host = '['.inet_ntop($bin).']';
    } else {
        $host = strtolower($host);
        if (!preg_match('/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9_-]*[a-z0-9])?)*\.?$/', $host)) return false;
    }
    $port = '';
    if (isset($parts['port'])) {
        if (($number = (int)$parts['port']) < 1 || $number > 65535) return false;
        if ($number != ($scheme === 'http' ? 80 : 443)) $port = ':'.$number;
    }
    $canonical = $scheme.'://'.$host.$port.(isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/');
    // 同样从原始 URL 取 query：尾随 ? 是一个真实的空 query，不能被 7.3 丢掉；值恰好为 "0" 也不能被 empty() 当成不存在。
    if (($query = strpos($url, '?')) !== false) $canonical .= substr($url, $query);
    // 列宽就是 255，超了写进去是被截断的半截 URL，投不出去也对不上黑名单
    return strlen($canonical) > 255 ? false : $canonical;
}

// 规范化失败必须留下来源。只返回 false 的话，事后只知道少了一次投递，不知道是谁的 inbox 坏了，也就没法去刷新那个 actor
function Club_Endpoint_Require($url, $context = []) {
    if (($canonical = Club_Endpoint_Normalize($url)) !== false) return $canonical;
    Club_Log_Event('warning', 'endpoint rejected, url cannot be normalized', array_merge(['url' => (string)$url], $context));
    return false;
}

// endpoint、blacklist、DNS 三处租约共用。数据库存 binary(16)，PHP 只经手 hex：原始 16 字节里有 NUL，绑进 utf8mb4 的模拟预处理连接就是一串截断的乱码
function Club_Token() {
    return bin2hex(random_bytes(16));
}

// endpoint 和 blacklist 领取共用：先无锁读候选，再逐条按主键 CAS；二级索引 UPDATE 与完成路径锁序相反，会形成 1213 死锁。
// 起点取 token 的前 8 bit，让并发的 worker 不要每轮都挤在最早的那一条上；抢输就换下一条，影响 0 行的 UPDATE 在 RC 下不留锁，换一条不会把锁集合摊大。
// 不用 mt_rand 是因为它只在 fork 之后首次使用才各自播种：master 将来但凡在 fork 之前碰一次 PRNG，几十个 worker 就会静默地同步挑同一条候选。token 是 random_bytes，按构造就没有这个前提。
// 候选都被抢走返回 null，跟「一条候选都没有」由调用方各自区分
function Club_Lease_Pick($candidates, $token, $attempt, $tries = 3) {
    $count = count($candidates); $start = hexdec(substr($token, 0, 2)) % $count;
    for ($i = 0; $i < min($tries, $count); $i++) {
        $picked = $candidates[($start + $i) % $count];
        if ($attempt($picked)) return $picked;
    }
    return null;
}

// 一次入队涉及的所有 target 的控制行。跟 task、queues 同一个事务提交，不会留下「有活动可投、没人调度」的半成品
function Club_Endpoint_Upsert($task) {
    global $db;
    // 分组列不能直接在 on duplicate key update 里引用，套一层 derived table 才行；order by 让批量 upsert 按主键的二进制序取锁，减少和别的入队互相咬住的机会。
    // greatest(retry_at, ...) 是硬边界：新活动不能把退避中的 endpoint 提前唤醒；next_at 为空的分支保证空 endpoint 收到新 queue 之后重新可调度。
    // 算出来的 next_at 必然非空（incoming 是 min(due_at)，而 blacklist target 根本入不了 queue），所以 idle_since 无条件清零：这一行重新排上了，不再是待回收的空行
    $pdo = $db->prepare('insert into `endpoints` (`url`, `next_at`) select `incoming`.`url`, `incoming`.`next_at` from ('.
        ' select `q`.`target` collate ascii_bin as `url`, min(`q`.`due_at`) as `next_at` from `queues` as `q` where `q`.`tid` = :tid group by `q`.`target` collate ascii_bin'.
        ') as `incoming` order by `incoming`.`url` collate ascii_bin on duplicate key update `next_at` = greatest(`endpoints`.`retry_at`,'.
        ' if(`endpoints`.`next_at` is null, `incoming`.`next_at`, least(`endpoints`.`next_at`, `incoming`.`next_at`))), `idle_since` = 0');
    return $pdo->execute([':tid' => $task]);
}

// 这一条往后推，故障计数不动：本站 DNS 没结果时用
function Club_Queue_Defer($id, $due) {
    global $db;
    $pdo = $db->prepare('update `queues` set `due_at` = :due where `id` = :id');
    $pdo->execute([':id' => $id, ':due' => $due]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

function Club_Queue_Retry($id, $retries, $due) {
    global $db;
    $pdo = $db->prepare('update `queues` set `due_at` = :due, `retries` = :retries where `id` = :id');
    $pdo->execute([':id' => $id, ':due' => $due, ':retries' => $retries]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

// cid 记的是签这批请求的群组，未必是扇出目标：它的私钥只用来签 HTTP 签名
function Club_Task_Create($type, $signer, $jsonld) {
    global $db;
    $pdo = $db->prepare('insert into `tasks`(`cid`,`type`,`jsonld`,`timestamp`) select `cid`, :type as `type`, :jsonld as `jsonld`, :timestamp as `timestamp` from `clubs` where `name` = :club');
    $pdo->execute([':type' => $type, ':club' => $signer, ':jsonld' => $jsonld, ':timestamp' => time()]);
    // 群组不存在时 insert-select 不写入任何行，此时 last_insert_id() 是上一条的残值
    return $pdo->rowCount() ? $db->lastInsertId() : false;
}

function Club_Queue_Insert($task, $target) {
    global $db;
    $pdo = $db->prepare('insert into `queues`(`tid`,`target`,`due_at`,`retries`) select :tid, :target, :now, 0 from dual where :check not in (select `target` from `blacklist`)');
    $result = $pdo->execute([':tid' => $task, ':target' => $target, ':check' => $target, ':now' => time()]);
    if (!$result) return false;
    // SQL 成功但没写行表示目标正在 blacklist；它是业务终态，不伪装成数据库异常，也不能告诉私信/Accept 调用方“已经入队”。
    if (!$pdo->rowCount()) {
        Club_Log_Event('debug', 'push target is blacklisted', ['target' => $target]);
        return null;
    }
    return true;
}

// 按 shared_inbox 的二进制顺序分页入队。不能把大群组全部 fetchAll 到 PHP；投稿者 inbox 也要通过同一个 UNION 游标插进全局顺序，否则它排在关注者之后会破坏并发事务的取锁顺序。
// 一次传进来多个群组时取它们关注者的并集：转发的原始报文对这几个群组是同一包，各建各的 task 分头扇出，同时关注了两个群组的实例就会收到两遍，而并集里的 distinct 天然把它去掉
// $origin 是透传转发的来源实例，整条 shared_inbox 一起排掉：那一包就是它发来的，本地早有，签名 actor 在它那儿是本地账号，收下也只会被丢弃。
// 同实例的其它关注者共用这个 inbox，少投的同样是他们已经有的那份
function Club_Queue_Insert_Followers($task, $clubs, $inbox = false, $origin = false) {
    global $db;
    $cursor = null; $page = 250; $now = time(); $invalid = []; $invalid_count = 0; $names = []; $binds = [];
    $clubs = array_values(to_array($clubs));
    foreach ($clubs as $i => $club) { $key = ':club'.$i; $names[] = $key; $binds[$key] = $club; }
    if ($origin !== false) { $skip = ' and u.shared_inbox collate ascii_bin <> convert(:origin using ascii) collate ascii_bin'; $binds[':origin'] = $origin; } else $skip = '';
    do {
        $after = isset($cursor) ? ' and u.shared_inbox collate ascii_bin > convert(:f_cursor using ascii) collate ascii_bin' : '';
        $source = 'select distinct u.shared_inbox collate ascii_bin as `target` from `followers` `f` join `clubs` `c` on f.cid = c.cid'.
            ' join `users` `u` on f.uid = u.uid where c.name in ('.implode(', ', $names).')'.$skip.$after;
        $params = $binds;
        if (isset($cursor)) $params[':f_cursor'] = $cursor;
        if ($inbox !== false) {
            // 参数跟列统一成 ascii_bin，避免连接字符集把 UNION 的结果提升回大小写不敏感排序。
            $source .= ' union select convert(:author using ascii) collate ascii_bin as `target`'.(isset($cursor)
                ? ' from dual where convert(:author_check using ascii) collate ascii_bin > convert(:a_cursor using ascii) collate ascii_bin' : '');
            $params[':author'] = $inbox;
            if (isset($cursor)) {
                $params[':author_check'] = $inbox;
                $params[':a_cursor'] = $cursor;
            }
        }
        $pdo = $db->prepare('select `target` from ('.$source.') `targets` order by `target` collate ascii_bin limit '.$page);
        $pdo->execute($params);
        $targets = $pdo->fetchAll(PDO::FETCH_COLUMN, 0); $count = count($targets);
        if (!$count) break;
        $cursor = $targets[$count - 1]; $valid = [];
        foreach ($targets as $target) {
            $canonical = Club_Endpoint_Normalize($target);
            if ($canonical === false || $canonical !== $target) {
                $invalid_count++;
                if (count($invalid) < 5) $invalid[] = (string)$target;
            } else $valid[] = $canonical;
        }
        // 上面的查询已经去重并按 ascii_bin 排好；这里保留 ORDER BY 作为真正写锁的硬边界。
        if ($valid) {
            $select = []; $insert = [':tid' => $task, ':now' => $now];
            foreach ($valid as $i => $target) {
                // 参数在 utf8mb4 连接上就是 utf8mb4，直接拿去 collate ascii_bin 会被 MySQL 按 1253 拒掉，整批扇出跟着回滚。
                // 转成 ascii 之后，跟 blacklist.target 的比较和下面的排序才都落在同一个字符集上
                $key = ':target'.$i; $insert[$key] = $target;
                $select[] = 'select convert('.$key.' using ascii) collate ascii_bin as `target`';
            }
            $pdo = $db->prepare('insert into `queues`(`tid`,`target`,`due_at`,`retries`) select :tid, `t`.`target`, :now, 0 from ('.implode(' union all ', $select).') `t`'.
                ' where not exists (select 1 from `blacklist` where `target` = `t`.`target`) order by `t`.`target` collate ascii_bin');
            $pdo->execute($insert);
        }
    } while ($count >= $page);
    if ($invalid) Club_Log_Event('warning', 'invalid cached follower endpoints skipped', ['clubs' => $clubs, 'count' => $invalid_count, 'targets' => $invalid]);
    return true;
}

// 这一行到此为止：投出去了、目标进了黑名单、或是重试到头
function Club_Queue_Delete($id, $task) {
    global $db;
    $pdo = $db->prepare('delete from `queues` where `id` = :id');
    $result = $pdo->execute([':id' => $id]);
    $deleted = $pdo->rowCount();
    Club_Log_Event('debug', $deleted ? 'queue deleted' : 'queue delete skipped, row not found', ['id' => $id, 'task' => $task]);
    return $result;
}

// 数组是本站自己组的活动，字符串是原样透传的远端活动（$type 由调用方给）。
// 透传的那份绝不能解码后重新编码：RsaSignature2017 签的是整包规范化的结果，而 json_decode 的关联数组模式会把 {} 变成 []，再编码出来签名就废了
function Club_Push_Enqueue($signer, $clubs, $activity, $direct, $inbox, $origin, &$error, &$reason) {
    global $db;
    if (!($task = Club_Task_Create('push', $signer, $activity))) {
        $reason = 'club-missing'; $error = 'club not found: '.$signer;
        return false;
    }
    $queued = $direct ? Club_Queue_Insert($task, $inbox) : Club_Queue_Insert_Followers($task, $clubs, $inbox, $origin);
    if ($queued === null) {
        // anti-blacklist 没产生 queue，刚建的 task 没有消费者；同一事务里收掉，调用方保留可重试的本地状态（例如待撤回 notice），等 endpoint 恢复后再尝试。
        $pdo = $db->prepare('delete from `tasks` where `tid` = :tid and not exists (select 1 from `queues` where `tid` = :tid_check)');
        $pdo->execute([':tid' => $task, ':tid_check' => $task]);
        $reason = 'blacklisted'; $error = 'target is blacklisted: '.$inbox;
        return false;
    }
    if ($queued === false) throw new PDOException('queue insert returned false');
    if (!Club_Endpoint_Upsert($task)) throw new PDOException('endpoint upsert returned false');
    return true;
}

function Club_Push_Activity($club, $activity, $inbox = false, $direct = false, $type = null, $origin = false, &$reason = null) {
    global $db;
    $reason = null; $clubs = array_values(to_array($club)); $club = $clubs[0];
    // 本站自己组的活动，actor 就是这个群组，对端除了 HTTP 签名没有别的东西能验归属，只能由它自己签。
    // 透传的转发相反：归属由报文自带的 LD 签名认，签名群组不参与判断，改用系统群组签有两个好处 ——
    // 收件实例不必为一条它没关注的群组的转发去拉那个 actor，管理员单独封了其中一个群组时，也不会把整包连同别的群组的关注者一起丢掉
    if (is_array($activity)) { $type = $activity['type']; $activity = Club_Json_Encode($activity); $signer = $club; }
    else $signer = Club_System() ?: $club;
    // 直接投递（Accept、私信）的目标是调用方现给的，规范化不了就没有可写库的 key。先建一个投不出去的 task 只会在队列里留一条谁都处理不掉的行，这里必须显式失败
    if ($direct && ($inbox = Club_Endpoint_Require($inbox, ['club' => $club, 'type' => $type])) === false) {
        $reason = 'invalid-endpoint';
        Club_Log_Event('error', 'push failed, target inbox is not a usable endpoint', ['club' => $club, 'type' => $type ?: 'unknown']);
        return false;
    }
    // 扇出时多带的那个 inbox 只是投稿者本人，坏了不该连累整群关注者的转发
    if (!$direct && $inbox !== false && ($inbox = Club_Endpoint_Normalize($inbox)) === false) {
        Club_Log_Event('warning', 'push author inbox skipped, url cannot be normalized', ['clubs' => $clubs]);
        $inbox = false;
    }
    $name = Club_Log_Name('outbox', [implode('-', $clubs), $type ?: 'unknown']);
    Club_Log_Write('info', 'outbox', $name.'_output', $activity);
    Club_Log_Write('debug', 'outbox', $name.'_server', $_SERVER);
    $commit = false; $error = ''; $owned = !$db->inTransaction();
    try {
        if ($owned) $commit = Club_DB_Transaction('push enqueue', function () use ($signer, $clubs, $activity, $direct, $inbox, $origin, &$error, &$reason) {
                return Club_Push_Enqueue($signer, $clubs, $activity, $direct, $inbox, $origin, $error, $reason);
            });
        else $commit = Club_Push_Enqueue($signer, $clubs, $activity, $direct, $inbox, $origin, $error, $reason);
    } catch (PDOException $e) {
        $error = $e->getMessage();
        // 外层 inbox 事务会负责整段重试；独立调用到最终一次仍失败时在这里落失败文件。
        if ($owned) {
            Club_Log_Event('error', 'push failed: '.$error);
            Club_Log_Write('error', 'outbox', $name.'_commit_failed', $error, 'txt');
        }
        throw $e;
    }
    if (!$commit) {
        Club_Log_Write('error', 'outbox', $name.'_commit_failed', $error ?: 'commit returned false', 'txt');
    // 带上 outbox 那组文件的基名：入站活动的关联标记是 inbox 的，跳不到发出去的报文
    } else Club_Log_Event('debug', 'push queued, '.($type ?: 'unknown'), ['clubs' => $clubs, 'signer' => $signer, 'target' => $direct ? $inbox : 'followers', 'outbox' => $name]);
    return (bool)$commit;
}

// 只有一条规则时允许省掉外面那层列表，两种形状都要认：漏了归一化的话，单条写法会被调用方 foreach 拆成一个个字段，每个字段各算一条规则
function Club_Limit_Rules($club) {
    global $config;
    $rules = $config['club']['limits'][$club] ?? null;
    if (empty($rules) || !is_array($rules)) return [];
    return isset($rules['type']) ? [$rules] : $rules;
}

// 逐条判断限流规则，返回第一条触发的 [提醒键, 变量]，都没触发返回 false
function Club_Limit_Check($club, $user, $content) {
    global $db;
    foreach (Club_Limit_Rules($club) as $rule) {
        $hours = (int)($rule['hours'] ?? 0);
        $limit = (int)($rule['limit'] ?? 0);
        if ($hours < 1 || $limit < 1) continue;
        $vars = ['club' => $club, 'hours' => $hours, 'limit' => $limit];
        $params = [':club' => $club, ':timestamp' => time() - $hours * 3600]; $where = '';
        switch ($type = strtolower($rule['type'] ?? 'user')) {
            case 'club': break;
            // 同一实例的用户共用一个 shared_inbox，用它区分投稿者的站点
            case 'site': $where = ' and u.shared_inbox = :site';
                $params[':site'] = $user['shared_inbox']; break;
            // 同一用户在本群组发过的相同正文
            case 'dupl': $where = ' and a.uid = :uid and a.content = :content';
                $params[':uid'] = $user['uid']; $params[':content'] = $content; break;
            default: $type = 'user'; $where = ' and a.uid = :uid';
                $params[':uid'] = $user['uid'];
        }
        $pdo = $db->prepare('select count(a.id) from `announces` `a` join `clubs` `c` on a.cid = c.cid join `users` `u` on a.uid = u.uid where c.name = :club and a.timestamp >= :timestamp'.$where);
        $pdo->execute($params);
        if ($pdo->fetch(PDO::FETCH_COLUMN, 0) >= $limit) return ['limit-'.$type, $vars];
    }
    return false;
}

// 用系统群组发私信提醒。传了 $reply 就作为那条帖子的回复发出，每条都回；不针对具体帖子的提醒才做冷却，避免刷屏
function Club_Notice_Send($actor, $type, $vars = [], $lang = null, $reply = null, $cooldown = 3600) {
    global $db, $base, $config;
    if (!($config['notice']['enabled'] ?? true) || empty($actor)) return false;
    if (!($club = Club_System()) || !($user = Club_Get_Actor($actor))) return false;

    if (!isset($reply)) {
        $pdo = $db->prepare('select max(`timestamp`) from `notices` where `uid` = :uid and `type` = :type');
        $pdo->execute([':uid' => $user['uid'], ':type' => $type]);
        $last = $pdo->fetch(PDO::FETCH_COLUMN, 0);
        // 用户报「没收到提醒」时，这两条决定了是发不出去还是压根没发
        if ($last && $last > time() - $cooldown) {
            Club_Log_Event('debug', 'notice on cooldown, '.$type, ['actor' => $actor, 'retry in' => ($last + $cooldown - time()).'s']);
            return false;
        }
    } else {
        // 逐条回复对刷屏用户等于反向刷屏，每人每天封顶
        $pdo = $db->prepare('select count(`id`) from `notices` where `uid` = :uid and `timestamp` >= :timestamp');
        $pdo->execute([':uid' => $user['uid'], ':timestamp' => time() - 86400]);
        if (($sent = $pdo->fetch(PDO::FETCH_COLUMN, 0)) >= ($limit = $config['notice']['limit'] ?? 20)) {
            Club_Log_Event('debug', 'notice daily limit reached, '.$type, ['actor' => $actor, 'sent' => $sent, 'limit' => $limit]);
            return false;
        }
    }
    $locale = Club_I18n_Locale($lang);
    $content = '<p><a href="'.$actor.'" class="u-url mention">@'.$user['name'].'</a> '.Club_I18n($type, $locale, $vars).'</p>';
    return Club_DB_Transaction('notice enqueue', function () use ($db, $base, $club, $user, $actor, $type, $reply, $locale, $content) {
        $pdo = $db->prepare('insert into `notices`(`uid`,`type`,`object`,`timestamp`) values (:uid, :type, :object, :timestamp)');
        $pdo->execute([':uid' => $user['uid'], ':type' => $type, ':object' => $reply, ':timestamp' => ($time = time())]);
        if (!($id = $db->lastInsertId())) return false;
        $club_url = $base.'/club/'.$club; $note_url = $club_url.'/notice/'.$id;
        $pdo = $db->prepare('update `notices` set `note` = :note where `id` = :id');
        $pdo->execute([':id' => $id, ':note' => $note_url]);
        // to 只有收件人、cc 为空，Mastodon 据此判定为私信
        $queued = Club_Push_Activity($club, [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $note_url.'/create',
            'type' => 'Create',
            'actor' => $club_url,
            'published' => gmdate('Y-m-d\TH:i:s\Z', $time),
            'to' => [$actor],
            'cc' => [],
            'object' => [
                'id' => $note_url,
                'type' => 'Note',
                'attributedTo' => $club_url,
                'inReplyTo' => $reply,
                'published' => gmdate('Y-m-d\TH:i:s\Z', $time),
                'content' => $content,
                // 键就是 Mastodon 认的语言标记，它取第一个键存进 status.language 来判断要不要给翻译
                'contentMap' => [$locale => $content],
                'to' => [$actor],
                'cc' => [],
                'tag' => [['type' => 'Mention', 'href' => $actor, 'name' => '@'.$user['name']]]
            ]
        ], $user['inbox'], true);
        if (!$queued) {
            $pdo = $db->prepare('delete from `notices` where `id` = :id');
            $pdo->execute([':id' => $id]);
            Club_Log_Event('warning', 'notice not recorded because enqueue was rejected', ['actor' => $actor, 'type' => $type]);
        }
        return $queued;
    });
}

// 用户删帖时把针对这条帖子的提醒一并撤回，不留孤儿回复。限定 actor，不然别人报一个帖子 id 就能把发给你的提醒清掉
function Club_Notice_Delete($object, $actor) {
    global $db;
    $pdo = $db->prepare('select n.id, n.note, u.actor, u.inbox, u.shared_inbox from `notices` `n`'.
        ' join `users` `u` on n.uid = u.uid where n.object = :object and u.actor = :actor and n.note is not null');
    $pdo->execute([':object' => $object, ':actor' => $actor]);
    $notices = $pdo->fetchAll(PDO::FETCH_ASSOC);
    // 入站 Delete 的 web 路径可以在撤回事务之外刷新一次；maintain 共用的 Revoke 绝不出网。
    if ($notices && Club_Notice_Endpoint($notices[0]) === false && ($user = Club_Get_Actor($actor))) {
        foreach ($notices as &$notice) {
            $notice['inbox'] = $user['inbox']; $notice['shared_inbox'] = $user['shared_inbox'];
        }
        unset($notice);
    }
    return Club_Notice_Revoke($notices);
}

// 迁移保留的非 canonical endpoint 不能被“顺手规范化”后继续使用；只有缓存值本身已经精确 canonical 才可派生 queue。个人 inbox 坏掉时，合法 shared inbox 是安全的后备目标。
function Club_Notice_Endpoint($notice) {
    foreach (['inbox', 'shared_inbox'] as $key) {
        $url = $notice[$key] ?? null;
        if (is_string($url) && ($canonical = Club_Endpoint_Normalize($url)) !== false && $canonical === $url) return $url;
    }
    return false;
}

// 只删本地记录的话对端会永远留着那条私信，所以要撤回。revoke 用稳定 id 游标扫一轮；坏 endpoint 留下原记录但本轮仍向后走，扫到底后再按 interval 退避，不能卡在前 20 条热循环。
function Club_Notice_Expire($days = 30, $limit = 20, $interval = 600) {
    global $db; static $last = 0, $cursor = 0, $cleanup = false;
    $now = time();
    if ($days < 1 || (!$cursor && !$cleanup && $last > $now - $interval)) return false;
    $limit = max(1, (int)$limit); $expire = $now - $days * 86400;
    if (!$cleanup) {
        $pdo = $db->prepare('select n.id, n.note, u.actor, u.inbox, u.shared_inbox from `notices` `n`'.
            ' join `users` `u` on n.uid = u.uid where n.timestamp <= :timestamp and n.note is not null and n.id > :cursor order by n.id limit '.$limit);
        $pdo->execute([':timestamp' => $expire, ':cursor' => $cursor]);
        $notices = $pdo->fetchAll(PDO::FETCH_ASSOC);
        // DB 异常时不能先推进 cursor；外层立即重试还要看见同一批。
        Club_Notice_Revoke($notices); Club_Stat('scheduler_db_ops');
        $count = count($notices);
        if ($count) $cursor = (int)$notices[$count - 1]['id'];
        if ($count >= $limit) return true;
        $cursor = 0; $cleanup = true;
    }
    // note 为空的行没发出去过，不用通知对端；删满一批时保持 cleanup phase 立即续跑。
    $pdo = $db->prepare('delete from `notices` where `timestamp` <= :timestamp and `note` is null order by `id` limit '.$limit);
    $pdo->execute([':timestamp' => $expire]);
    $deleted = $pdo->rowCount(); Club_Stat('scheduler_db_ops');
    if ($deleted) Club_Monitor_Count('notices_deleted', $deleted);
    if ($deleted >= $limit) return true;
    $cleanup = false; $last = time();
    return false;
}

function Club_Notice_Revoke($notices) {
    global $db, $base;
    if (!$notices) return 0;
    // maintain 也走这里，绝不能触发 resolver/cURL；只用查询带来的两个缓存值做纯校验。两个都不可用的 notice 留着等 web Delete 刷新，或下一轮缓存自然更新。
    $ready = []; $failed = [];
    foreach ($notices as $notice) {
        $actor = (string)($notice['actor'] ?? '');
        if (($endpoint = Club_Notice_Endpoint($notice)) === false) {
            $failed[] = ['id' => (int)($notice['id'] ?? 0), 'actor' => $actor];
            continue;
        }
        $notice['inbox'] = $endpoint; $ready[] = $notice;
    }
    if ($failed) Club_Log_Event('warning', 'notice revoke deferred, recipient endpoint unavailable', ['count' => count($failed), 'notices' => array_slice($failed, 0, 5)]);
    if (!$ready || !($club = Club_System())) return 0;
    usort($ready, function ($a, $b) { return (int)$a['id'] <=> (int)$b['id']; });
    $club_url = $base.'/club/'.$club;
    return Club_DB_Transaction('notice revoke', function () use ($db, $club, $club_url, $ready) {
        $deleted = 0;
        foreach ($ready as $notice) {
            // Club_Notice_Delete 与 expiry 可能撞上；先按 id 锁定仍存在的原记录，避免两边各自创建一条 Delete task。所有 notice 都按 id 升序取锁。
            $pdo = $db->prepare('select `id` from `notices` where `id` = :id and `note` = :note for update');
            $pdo->execute([':id' => $notice['id'], ':note' => $notice['note']]);
            if (!$pdo->fetch(PDO::FETCH_COLUMN, 0)) continue;
            $queued = Club_Push_Activity($club, [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $notice['note'].'/delete',
                'type' => 'Delete',
                'actor' => $club_url,
                'to' => [$notice['actor']],
                'object' => [
                    'id' => $notice['note'],
                    'type' => 'Tombstone',
                    'atomUri' => $notice['note']
                ]
            ], $notice['inbox'], true);
            if (!$queued) {
                Club_Log_Event('warning', 'notice revoke retained because delete was not queued', ['notice' => (int)$notice['id'], 'actor' => $notice['actor']]);
                continue;
            }
            $pdo = $db->prepare('delete from `notices` where `id` = :id');
            $pdo->execute([':id' => $notice['id']]);
            $deleted += $pdo->rowCount();
        }
        return $deleted;
    });
}

function Club_Task_Cleanup($age = 30, $limit = 200) {
    global $db; $limit = max(1, (int)$limit);
    $pdo = $db->prepare('delete from `tasks` where `timestamp` <= :timestamp and not exists (select 1 from `queues` where queues.tid = tasks.tid) limit '.$limit);
    $pdo->execute([':timestamp' => time() - max(0, (int)$age)]);
    Club_Stat('scheduler_db_ops'); $rows = $pdo->rowCount();
    if ($rows) Club_Monitor_Count('tasks_deleted', $rows);
    return $rows >= $limit;
}

// 群组 inbox 和 shared inbox 共用，避免两处日志走偏。基名由调用方算好传进来：它同时是 event 里的关联标记，两边必须是同一个值
function Club_Log_Inbox($name, $input, $verify) {
    global $verify_reason, $verify_signed;
    // 验签没过时正文跟着降到 warning 一起留：只有失败原因没有请求体，排查时等于少了一半
    Club_Log_Write($verify ? 'info' : 'warning', 'inbox', $name.'_input', $input);
    Club_Log_Write('debug', 'inbox', $name.'_server', $_SERVER);
    if (!$verify) Club_Log_Write('warning', 'inbox', $name.'_verify_failed', 'reason: '.($verify_reason ?? '-')."\n\nsignature: ".($_SERVER['HTTP_SIGNATURE'] ?? '-').
        "\n\ndigest: ".($_SERVER['HTTP_DIGEST'] ?? '-')."\n\nsigned string:\n".($verify_signed ?? '-'), 'txt');
}

// inbox 链路上每个提前 return 的去向。这条链上十几个 return 从外面看长得一模一样：没数据、没报错、logs/inbox/ 里躺着一个正常的 _input 文件，只能回源码里数 return。
// 写法沿用 Club_Exist_Fail 那套，在返回点直接 return Club_Inbox_Skip(...)
function Club_Inbox_Skip($reason, $context = []) {
    Club_Log_Event('debug', 'inbox skip, '.$reason, $context);
}

// inbox 的应答只发一次，链路上任何一处给出终局状态之后，外层那个 202 兜底就不能再覆盖它。用它自己的标记而不是 headers_sent()：开着输出缓冲时前一次输出还没落到网络上，那个函数仍然答 false。
// 对端只按状态码分类，选错一边就是丢活动或者无限重投：本站发出去的 4xx 都取自不会被重投的那半（400、403、413），5xx 会被重投，所以只有真的临时故障才给 5xx。
// 401、408、429 不在其中 —— 投递侧同样把这三个当可恢复故障重试，见 ActivityPub_POST 的分类
function Club_Inbox_Reply($status, $message, $retry = 0) {
    static $sent = false;
    if ($sent) return; $sent = true;
    if ($retry) header('Retry-After: '.$retry);
    Club_Json_Output(['message' => $message], 0, $status);
}

// 验签过了，但本站拿不到能用的 actor 文档，没有它就落不了库。拉取失败的原因决定对端该不该再来一次：404、非法文档、非法 endpoint 重投多少遍都是同一个结果，
// 回 5xx 就是让对端一直投；解析不动、连不上、对端 5xx、冷却中那些则相反，回终局 4xx 等于替它把这条活动丢了。两个调用点是同一个判断
function Club_Inbox_Unresolved($actor) {
    global $fetch_retry;
    Club_Log_Event('warning', 'inbox '.($fetch_retry ? 'deferred' : 'rejected').', actor document is unusable', ['actor' => $actor]);
    return $fetch_retry ? Club_Inbox_Reply(503, 'Actor is temporarily unavailable', 60) : Club_Inbox_Reply(400, 'Actor document is unusable');
}

// 入站已验证，但它必须派生的 direct enqueue 暂时被 blacklist 挡住：回滚本轮状态并让对端重投。
class Club_Inbox_Deferred extends RuntimeException {}
// endpoint/club 等前提本身终局无效：同样回滚本轮状态，但不能用 5xx 制造无限重投。
class Club_Inbox_Rejected extends RuntimeException {}

// inbox 的对外入口。请求体在这里读：那是对端完全可控的字节，先看 Content-Length 再决定要不要读进来，读完再判等于把本进程的内存占用交给对方。
// DB 抛异常必须在 event 里也留一行，否则那边是「inbox in」之后戛然而止，判断不出是没匹配到分支还是中途挂了
function Club_Inbox_Process($club = null, $input = null) {
    // 上限跟 Mastodon 的 ActivityPub::Activity::MAX_JSON_SIZE 取同一个数，它那边也是写死的常量。一条活动多大是协议决定的，不是每站不一样的东西
    $limit = 1024 * 1024;
    // Content-Length 可以缺失（分块传输）也可以撒谎，所以多读一个字节再复核一次，两处都拦住才算真的封顶
    $long = (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
    // 请求体正常在这里读。传进来的那条口子只有 tests/activitypub.php 的重放在用：CLI 下 php://input 读不出东西，而下面这一整段应答分类正是重放要验的
    if (!isset($input)) $input = $long ? '' : file_get_contents('php://input', false, null, 0, $limit + 1);
    // 读不出来是本站这边的输入流故障，不是对端发了个空包。当成空正文的话下面会因为解不出 type 回一个终局 400，那条活动就再也不会重投了
    if ($input === false) {
        Club_Log_Event('error', 'inbox aborted, cannot read the request body', ['club' => $club ?? 'shared', 'length' => $_SERVER['CONTENT_LENGTH'] ?? '-']);
        return Club_Inbox_Reply(503, 'Unable to read the request body', 60);
    }
    if ($long || strlen($input) > $limit) {
        Club_Log_Event('warning', 'inbox rejected, request body is too large', ['club' => $club ?? 'shared', 'length' => $_SERVER['CONTENT_LENGTH'] ?? '-', 'limit' => $limit]);
        return Club_Inbox_Reply(413, 'Request body is too large');
    }
    try {
        Club_Inbox_Dispatch($input, $club);
        // 合法且认证成功的活动，无论真处理了、判重跳过还是没有对应处理分支，对端要的都是同一句「收下了，别再投」
        Club_Inbox_Reply(202, 'Accepted');
    }
    catch (Club_Inbox_Deferred $e) {
        Club_Log_Event('warning', 'inbox deferred, outbound enqueue is temporarily blocked', ['error' => $e->getMessage()]);
        Club_Inbox_Reply(503, 'Temporarily unable to enqueue the required response', 300);
    }
    catch (Club_Inbox_Rejected $e) {
        Club_Log_Event('warning', 'inbox rejected, required outbound response cannot be enqueued', ['error' => $e->getMessage()]);
        Club_Inbox_Reply(400, 'Required response target is invalid');
    }
    catch (PDOException $e) {
        Club_Log_Event('error', 'inbox aborted, database error', ['error' => $e->getMessage()]);
        // 中断可能发生在 Club_Log_Inbox 之前，那样 event 里的关联标记会指向一个不存在的文件。报文是查这类中断的唯一依据，非补不可；已经写过的话同名覆盖，无害
        if ($name = Club_Log_Ref()) Club_Log_Write('error', 'inbox', $name.'_input', $input);
        // 写不进库是临时故障，503 + Retry-After 是明确的「稍后再来」；换成 5xx 里的 500 对端多半也会重投，但那是「本站有 bug」的意思，未捕获异常才归它
        Club_Inbox_Reply(503, 'Temporarily unable to store the activity', 60);
    }
}

// $club（null 即 shared inbox）只进日志：两个入口的活动都从 to/cc 里认群组，处理没有一步不同，分成两套只会让验签和去重两边各长各的
function Club_Inbox_Dispatch($input, $club = null) {
    global $db, $config, $verify_reason, $verify_retry;
    $jsonld = is_array($jsonld = json_decode($input, 1)) ? $jsonld : [];
    // 顶层数组是合法 JSON-LD（node object 的数组），Foundkey 一类实现这么发。单个活动拆开照常处理；多元素是活动集合，只认第一条等于把其余的静默丢掉，那种不收
    $wrapped = count($jsonld) === 1 && isset($jsonld[0]) && is_array($jsonld[0]);
    if ($wrapped) $jsonld = $jsonld[0];
    // 拆过的包不能再转发：原始字节仍是数组形态，下游多半跟我们一样只认单个对象。清掉之后走的就是对端没签 LD 签名时那条路 —— 本站照删、群发 Undo 撤回，只是不转原报文。
    // $input 得留着，日志里那份要的是对端发来的原样字节
    $payload = $wrapped ? null : $input;
    // type 会进日志文件名，不限成纯字母的话对端能用 ../ 穿出 logs 目录
    $type = is_string($t = $jsonld['type'] ?? '') && preg_match('/^[A-Za-z]+$/', $t) ? $t : '';
    $actor = Club_Object_Id($jsonld['actor'] ?? '');
    // actor 必须是外站的绝对地址：本站自己的 activity 不该从 inbox 进来，不是 URL 的也没法验签
    $host = $actor === '' ? '' : (string)parse_url($actor, PHP_URL_HOST);

    // 基名一次请求只算一次：它既是 logs/inbox/ 下那组文件的前缀，也是 event 里的关联标记，两边必须完全一致，否则从 event 定位报文时对不上。销号那条多带一段方便肉眼筛
    $parts = [$club ?? 'shared_inbox', $type ?: 'unknown'];
    if ($type === 'Delete' && $actor !== '' && $actor === Club_Object_Id($jsonld['object'] ?? '')) $parts[] = 'actor';
    Club_Log_Ref($name = Club_Log_Name('inbox', $parts));
    // 转发那一半会因此降级，不留痕的话事后看不出这条为什么只撤回没转发
    if ($wrapped) Club_Log_Event('debug', 'inbox unwrapped json-ld array, relay disabled', ['club' => $club ?? 'shared', 'type' => $type ?: 'unknown']);

    if ($type === '' || $host === '' || strcasecmp($host, $config['base']) === 0) {
        // 这三种都进不了验签，不留痕的话冒充本站身份这种明显的攻击特征就完全看不到
        $reason = $type === '' ? 'invalid type: '.substr((string)($jsonld['type'] ?? ''), 0, 100)
            : ($host === '' ? 'actor is not a url: '.substr($actor, 0, 200) : 'actor claims local host: '.substr($actor, 0, 200));
        Club_Log_Write('warning', 'inbox', $name.'_input', $input);
        Club_Log_Write('warning', 'inbox', $name.'_rejected', $reason, 'txt');
        Club_Log_Event('warning', 'inbox rejected, '.$reason, ['club' => $club ?? 'shared']);
        return Club_Inbox_Reply(400, 'Request is invalid');
    }
    $jsonld['actor'] = $actor;
    // 每条入站活动在 event 里留一行，logs/inbox/ 是按请求切的文件，翻不出时间线
    Club_Log_Event('debug', 'inbox in, '.$type, ['club' => $club ?? 'shared', 'actor' => $actor, 'object' => Club_Object_Id($jsonld['object'] ?? '')]);

    // 销号和改资料是广播给所有见过的实例的，绝大多数是我们从没见过的用户。
    // 这两类都只作用于 actor 自己，本地没缓存过它就是空操作：验签纯属白烧一次 RSA，还会记一堆 unknown actor 的失败日志（Update 那条更糟，默认会顺带触发一次拉取）。
    // 判据与 Mastodon 的 skip_unknown_actor_activity 一致，同样放在验签之前
    if (in_array($type, ['Delete', 'Update'], true) && $actor === Club_Object_Id($jsonld['object'] ?? '') && !Club_Has_Actor($actor)) {
        // 这条路不验签也不走 Club_Log_Inbox，debug 下得自己补一份报文，否则 event 里这条 skip 的关联标记在 logs/inbox/ 下找不到对应文件
        Club_Log_Write('debug', 'inbox', $name.'_input', $input);
        return Club_Inbox_Skip('broadcast from actor we never cached', ['type' => $type, 'actor' => $actor]);
    }

    // 对端注销账号，清掉本地缓存，关注关系靠外键级联删除
    if ($type == 'Delete' && $actor === Club_Object_Id($jsonld['object'] ?? '')) {
        $verify = ActivityPub_Verification($input, false) && ActivityPub_Verify_Actor($actor);
        // 销号会连带级联删掉关注关系，是破坏性最大的一条，成没成都要留痕
        Club_Log_Inbox($name, $input, $verify);
        // 这条从不吃未验签的包，inbox-verify 关掉也一样。给 202 对端会当作已经删干净了，所以要明说是签名的问题；这里不拉公钥（销号的 actor 拉了也是 410），没有会自愈的失败，一律终局
        if (!$verify) return Club_Inbox_Reply(403, 'Signature verification failed');
        // 查的是即将被删掉的那行 users，只能赶在删除事务前面。这个人没了，同实例的其它用户还在，那条 shared_inbox 照样该提前探活
        Club_Blacklist_Sooner($actor);
        // 删除和它派生的转发队列必须一起提交：分开提交时中途出错会留下删了一半的名单，而对端重投的那一包在上面 Club_Has_Actor 那里就被挡掉，剩下的群组永远等不到 Delete
        Club_DB_Transaction('actor delete inbox', function () use ($db, $jsonld, $payload, $actor) {
            // 先锁住 users 那行再读 activities：并发的投稿插 activities 时要拿这行的外键共享锁，不先锁的话，读完名单到删除之间落库的那个群组会被级联一并删掉 ——
            // 它的关注者已经收到了转嘟，却不在转发名单里。锁不到行说明并发的重投已经删过，本站没有可匹配的数据，转发也就无从谈起
            $pdo = $db->prepare('select `uid`, `shared_inbox` from `users` where `actor` = :actor for update');
            $pdo->execute([':actor' => $actor]);
            if (!($user = $pdo->fetch(PDO::FETCH_ASSOC))) return Club_Inbox_Skip('actor delete claimed by a concurrent delivery', ['actor' => $actor]);
            $uid = $user['uid'];
            // 投稿去过哪些群组只能在删之前问：users 一删，activities 跟着级联没了。
            // 每条投稿的群组集合各不相同，distinct 出来多少行由对端决定，整份拉进内存等于把销号请求的内存占用交给对方；按主键翻页归并，名单本身封顶在本站群组数
            $relay_clubs = []; $cursor = 0;
            $pdo = $db->prepare('select `id`, `clubs` from `activities` where `uid` = :uid and `id` > :cursor order by `id` limit 500');
            do {
                $pdo->execute([':uid' => $uid, ':cursor' => $cursor]);
                $rows = $pdo->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $cursor = $row['id'];
                    foreach (json_decode($row['clubs'], 1) ?: [] as $relay_club) $relay_clubs[$relay_club] = true;
                }
            } while (count($rows) >= 500);
            $pdo = $db->prepare('delete from `users` where `uid` = :uid');
            $pdo->execute([':uid' => $uid]);
            Club_Log_Event('info', 'actor deleted: '.$actor.', '.$pdo->rowCount().' row(s)');
            // 本站把他的投稿扇给过这些群组的关注者，那边本地留着他的副本和帖子，作者不在他们那儿，谁也不会再来删。原始 Delete 一并转出去才清得掉
            if ($relay_clubs && isset($payload) && Club_Relay_Allow($jsonld, $payload, $actor, 'Delete')) {
                $relay_clubs = array_keys($relay_clubs);
                $relayed = Club_Push_Activity($relay_clubs, $payload, false, false, 'Delete-relay', $user['shared_inbox']);
                Club_Log_Event($relayed ? 'info' : 'warning', 'actor delete '.($relayed ? 'relayed' : 'not relayed'), ['actor' => $actor, 'clubs' => $relay_clubs]);
            }
            return true;
        });
        return;
    }
    $verify = ActivityPub_Verification($input) && ActivityPub_Verify_Actor($actor);
    Club_Log_Inbox($name, $input, $verify);
    if (($config['node']['inbox-verify'] ?? true) && !$verify) {
        // 详情在 logs/inbox/*_verify_failed 里，这里只留一行好对时间线
        Club_Inbox_Skip('verification failed', ['actor' => $actor, 'reason' => $verify_reason ?? '-']);
        // 缺签名、Digest 对不上、签名与当前公钥不符都是终局的，回 403 让对端别再投；只有公钥暂时拉不到那种回 5xx，理由见 ActivityPub_Verification 末尾
        return $verify_retry ? Club_Inbox_Reply(503, 'Unable to verify the signature right now', 60) : Club_Inbox_Reply(403, 'Signature verification failed');
    }
    if (!$verify) Club_Log_Event('warning', 'inbox unverified but accepted, inbox-verify is off', ['actor' => $actor, 'reason' => $verify_reason ?? '-']);
    // 验过签才算数：不验签就凭 actor 提前探活，等于让任何人点名让我们去敲谁
    else Club_Blacklist_Sooner($actor);
    // 系统群组只负责发私信，不接受关注也不转发投稿。身份是可信的，拦它的是本站策略，所以是 403 而不是 401
    if (isset($club) && Club_System_Name($club)) {
        Club_Inbox_Skip('system club accepts nothing', ['club' => $club, 'type' => $type]);
        return Club_Inbox_Reply(403, 'This actor does not accept this activity');
    }

    switch ($type) {
        case 'Create': Club_Announce_Process($jsonld); break;
        case 'Follow': Club_Follow_Process($jsonld); break;
        case 'Undo': Club_Undo_Process($jsonld); break;
        // 转发要发原始报文，$jsonld 这边被归一化过（actor 拍平、Tombstone 补形状），不能拿去发
        case 'Update': Club_Update_Process($jsonld, $payload); break;
        case 'Delete':
            // object 可以是内嵌的 Tombstone，也可以直接是被删对象的 id
            if (!isset($jsonld['object']['type'])) $jsonld['object'] = ['id' => Club_Object_Id($jsonld['object'] ?? ''), 'type' => 'Tombstone'];
            if ($jsonld['object']['type'] == 'Tombstone') Club_Tombstone_Process($jsonld, $payload);
            else Club_Inbox_Skip('delete of non-tombstone', ['object type' => $jsonld['object']['type']]);
            break;
        default: Club_Inbox_Skip('no handler for type', ['type' => $type, 'actor' => $actor]);
    }
}

function Club_Announce_Process($jsonld) {
    global $db, $base, $config, $public_streams, $club_reason;
    if (!is_array($jsonld['object'] ?? null) || !($object = Club_Object_Id($jsonld['object']['id'] ?? ''))) return Club_Inbox_Skip('create without object id', ['actor' => $jsonld['actor']]);
    // object 必须属于发送者，否则能让群组替别人转发他的帖子
    $author = Club_Object_Id($jsonld['object']['attributedTo'] ?? '');
    if ($author ? $author !== $jsonld['actor'] : parse_url($object, PHP_URL_HOST) !== parse_url($jsonld['actor'], PHP_URL_HOST)) {
        // 验签是过的，所以 inbox 那边不会有 verify_failed，这里不记就彻底看不见
        Club_Log_Event('warning', 'announce author mismatch', ['actor' => $jsonld['actor'], 'author' => $author, 'object' => $object]);
        return;
    }
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $object]);
    if (!$pdo->fetch(PDO::FETCH_ASSOC)) {
        $prefix = $base.'/club/';
        $lang = Club_I18n_Detect($jsonld['object']);
        foreach ($to = array_merge(to_array($jsonld['to'] ?? []), to_array($jsonld['cc'] ?? [])) as $cc)
            if (is_string($cc) && $prefix == substr($cc, 0, strlen($prefix))) {
                // 系统群组不是投稿目标，被 @ 到也不转发
                if (Club_System_Name($name = explode('/', substr($cc, strlen($prefix)))[0])) continue;
                if ($club = Club_Exist($name)) $clubs[$club] = 1;
                // 只在建群限速时提醒，名字不合法之类的不打扰用户
                elseif ($club_reason == 'create limited') Club_Notice_Send($jsonld['actor'], 'create-limit', [], $lang);
            }
        if (!empty($clubs) && ($clubs = array_keys($clubs)) && in_array($public_streams, $to)) {
            sort($clubs, SORT_STRING);
            if ($actor = Club_Get_Actor($jsonld['actor'])) {
                return Club_DB_Transaction('create inbox', function () use ($db, $base, $public_streams, $clubs, $actor, $jsonld, $object, $lang) {
                // 同一条内容可能同时投到 shared inbox 和群组 inbox，靠唯一键去重
                $pdo = $db->prepare('insert ignore into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
                $pdo->execute([':uid' => $actor['uid'], ':type' => 'Create', ':clubs' => Club_Json_Encode($clubs), ':object' => $object, ':timestamp' => ($time = time())]);
                if ($pdo->rowCount() && ($activity_id = $db->lastInsertId())) {
                    // 正文和 CW 都是对端给的，不是字符串就当没有，否则会带着数组进 PDO
                    $content = strip_tags(is_string($c = $jsonld['object']['content'] ?? '') ? $c : '');
                    $summary = is_string($s = $jsonld['object']['summary'] ?? null) ? $s : null;
                    $published = strtotime(is_string($p = $jsonld['object']['published'] ?? '') ? $p : '') ?: $time;
                    $announced = [];
                    foreach ($clubs as $club) {
                        // 触发限流就跳过这个群组，并回复到原帖上让用户知道撞了哪条规则
                        if ($reject = Club_Limit_Check($club, $actor, $content)) {
                            Club_Log_Write('info', 'filter', [$club, $reject[0]], $jsonld);
                            Club_Log_Event('info', 'announce filtered, '.$reject[0], ['club' => $club, 'actor' => $jsonld['actor'], 'object' => $object]);
                            // 一条帖子只回一次，即使撞了多个群组的规则
                            if (empty($notified) && ($notified = true)) Club_Notice_Send($jsonld['actor'], $reject[0], $reject[1], $lang, $object);
                            continue;
                        } $club_url = $base.'/club/'.$club;
                        if (!Club_Push_Activity($club, [
                            '@context' => 'https://www.w3.org/ns/activitystreams',
                            'id' => $club_url.'/activity#'.$activity_id.'/announce',
                            'type' => 'Announce',
                            'actor' => $club_url,
                            'published' => gmdate('Y-m-d\TH:i:s\Z', $time),
                            'to' => [$club_url.'/followers'],
                            'cc' => [$jsonld['actor'], $public_streams],
                            'object' => $object
                        ], $actor['shared_inbox'])) continue;
                        $announced[] = $club;
                        $pdo = $db->prepare('insert ignore into `announces`(`cid`,`uid`,`activity`,`summary`,`content`,`timestamp`) select `cid`, :uid as `uid`,'.
                            ' :activity as `activity`, :summary as `summary`, :content as `content`, :timestamp as `timestamp` from `clubs` where `name` = :club');
                        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':activity' => $activity_id, ':summary' => $summary, ':content' => $content, ':timestamp' => $published]);
                    }
                    // 只保留真正转发出去的群组，撤回时才不会对没转发过的发 Undo
                    if ($announced != $clubs) {
                        $pdo = $db->prepare('update `activities` set `clubs` = :clubs where `id` = :id');
                        $pdo->execute([':id' => $activity_id, ':clubs' => Club_Json_Encode($announced)]);
                    }
                    // 一条投稿最终落到哪几个群组，是这整条链上唯一值得在 info 级别看见的结果
                    Club_Log_Event($announced ? 'info' : 'warning', 'announce '.($announced ? 'queued' : 'produced nothing'),
                        ['object' => $object, 'clubs' => $announced, 'skipped' => array_values(array_diff($clubs, $announced))]);
                // 同一条内容同时投到群组 inbox 和 shared inbox 时，输的那次走这里
                } else Club_Inbox_Skip('create already claimed by a concurrent delivery', ['object' => $object]);
                });
            } else Club_Inbox_Unresolved($jsonld['actor']);
        // $clubs 在上面的条件里已经被 array_keys 转成列表了，没进那步则还没定义
        } else Club_Inbox_Skip('create not addressed to a club, or not public', ['object' => $object, 'clubs' => $clubs ?? [], 'to' => $to]);
    } else Club_Inbox_Skip('create already processed', ['object' => $object]);
}

// 转发的两条共同前提。一是得带 LD 签名：对端只能从 HTTP 签名验到群组，验不到原作者，没签名的转过去必被丢弃。
// 二是不能大到离谱：转发存进 tasks.jsonld 的是对端完全可控的原始字节，出队时按关注实例数逐个扇出。2 万字的中文投稿约 118 KB，乘上千个实例就是上百 MB 出站，不封顶等于开放放大器。
// 上限默认写死在代码里，config.php 没同步过去时也得有个数
function Club_Relay_Allow($jsonld, $input, $object, $type) {
    global $config; $type = strtolower($type);
    if (empty($jsonld['signature']['signatureValue'])) {
        Club_Log_Event('info', $type.' not relayed, no ld signature', ['object' => $object]);
        return false;
    }
    if (($size = strlen($input)) > ($limit = ($config['club']['relay-limit'] ?? 512) * 1024)) {
        Club_Log_Event('warning', $type.' not relayed, payload too large', ['object' => $object, 'size' => $size, 'limit' => $limit]);
        return false;
    }
    return true;
}

// 计票包的版本号在活动 id 的 #updates/<poll.updated_at> 后缀里（UpdatePollSerializer），随每一轮计票递增，是这类包唯一能用来判重的量：
// object 里没有对应字段，NoteSerializer 的 updated 只在 edited_at 有值时才输出，有人投票并不算编辑。编辑包（UpdateNoteSerializer）的 id 是同一个形状，重放时也会落到这里，转出去就是重复扇出。
// 它顶层有 published，值就是 edited_at；计票包的顶层只有 id、type、actor、to 四项。
//
// 类型和选项两项都要，跟 Mastodon 的 PollParser#valid? 对齐；type 可以是数组
function Club_Poll_Revision($jsonld, $object) {
    if (isset($jsonld['published'])) return 0;
    if (!in_array('Question', to_array($object['type'] ?? []), true)) return 0;
    if (!is_array($object['oneOf'] ?? null) && !is_array($object['anyOf'] ?? null)) return 0;
    if (!preg_match('#\#updates/([0-9]{1,10})$#', Club_Object_Id($jsonld['id'] ?? ''), $matches)) return 0;
    // 这个数字是对端随手写的。放一个远期值进去，等于把这条帖子后面的计票全挡在门外
    return ($revision = (int)$matches[1]) > time() + 86400 ? 0 : $revision;
}

// 原作者编辑帖子后，Mastodon 只把 Update 发给自己的关注者。A 和 B 不在同一实例、之间也没有关注关系时，B 只是通过群组的 Announce 拿到的原帖，收不到这条 Update，本地那份就永远停在旧版本。
// 投票的计票更新同理，群组的关注者看到的票数会一直停在他们收到 Announce 的那一刻。Update 自带 RsaSignature2017，对端验得出原作者，所以整包原样转出去即可
function Club_Update_Process($jsonld, $input) {
    global $db;
    if (!is_array($object = $jsonld['object'] ?? null) || !($id = Club_Object_Id($object['id'] ?? ''))) return Club_Inbox_Skip('update without object id', ['actor' => $jsonld['actor']]);
    $edited = strtotime(is_string($u = $object['updated'] ?? '') ? $u : '') ?: 0;
    // 「这条帖子本站真的 Announce 过」加「发送者就是原作者」，两条合起来就是准入闸门：入站验签已经证明 HTTP 签名属于 $jsonld['actor']，这里再确认那个 actor 正是这条帖子的作者。
    // 少了它，任何人往 inbox 推一包活动都能改本站的记录、并被扇到全部关注者。注意这已经足够「我们自己」相信作者，所以本地落库照做；
    // LD 签名是给第三方验的，只决定能不能转发出去，不该拦住本地那一半——否则 GtS 一类不签名的实现连列表页都跟不上
    $pdo = $db->prepare('select a.id, a.updated, a.clubs, u.shared_inbox from `activities` `a` join `users` `u` on a.uid = u.uid where a.object = :object and a.type = :type and u.actor = :actor');
    $pdo->execute([':object' => $id, ':type' => 'Create', ':actor' => $jsonld['actor']]);
    // 本站没转发过这条帖子，或者发送者不是原作者。后者在正常联邦里不该出现，值得看见
    if (!($activity = $pdo->fetch(PDO::FETCH_ASSOC))) return Club_Inbox_Skip('update for a post we never announced, or not from its author', ['actor' => $jsonld['actor'], 'object' => $id]);
    // 计票包按形状认，不拿库里的 updated 反推：漏收或还没处理编辑包时，后面几个计票包带的是同一个 object.updated，反推会把它们全判成编辑，
    // 并发下只有一包过得了 updated 的 CAS，被判重的那包若是投票关闭前的最后一次计票，关注者就永远停在旧票数。两列各判各的。updated 是正文的版本号，就是 Mastodon 的 statuses.edited_at；
    // 计票要判重是因为本站把包原样转出去，重放就是重复扇出，Mastodon 不转发、票数重复应用一次没副作用，那边根本不判
    $poll = (bool)($revision = Club_Poll_Revision($jsonld, $object));
    // 计票包也带着编辑后的正文，漏收编辑包时顺手把本地副本补上
    $edit = $edited > (int)$activity['updated'];
    if (!$poll) {
        if (!$edit) return Club_Inbox_Skip('update is not newer than what we relayed, and not a poll tally', ['object' => $id, 'updated' => gmdate('Y-m-d\TH:i:s\Z', $edited)]);
        $revision = $edited;
    }
    $content = strip_tags(is_string($c = $object['content'] ?? '') ? $c : '');
    $summary = is_string($s = $object['summary'] ?? null) ? $s : null;
    $clubs = json_decode($activity['clubs'], 1) ?: [];
    $what = $poll ? 'poll update' : 'update';
    $column = $poll ? 'polled' : 'updated';
    $vars = ['object' => $id, 'clubs' => $clubs, 'revision' => gmdate('Y-m-d\TH:i:s\Z', $revision)];
    return Club_DB_Transaction($what.' inbox', function () use ($db, $activity, $revision, $content, $summary, $clubs, $vars, $jsonld, $input, $id, $poll, $edit, $edited, $what, $column) {
        // 判重、本地副本和全部转发队列必须一起提交；回滚后同一包才能重新通过版本闸门。上面那次比较用的是事务外读到的 updated，中间可能已经有新版本落库：这条 CAS 在库里重比一次，输的那包连正文都写不到
        $pdo = $db->prepare('update `activities` set `'.$column.'` = :revision where `id` = :id and `'.$column.'` < :revision');
        $pdo->execute([':id' => $activity['id'], ':revision' => $revision]);
        if (!$pdo->rowCount()) return Club_Inbox_Skip($what.' is a duplicate or older than what we relayed', ['object' => $id, 'revision' => gmdate('Y-m-d\TH:i:s\Z', $revision)]);
        // 计票包补正文得自己再 CAS 一次 updated：$edit 是事务外读的，中间可能已经有更新的编辑落库，无条件写就是拿旧正文盖掉新的，而 updated 还停在新版本，之后再没有包会来修这份不一致。
        // 推进 updated 不会让晚到的编辑包漏转发：这一包携带并转出去的就是同一份完整对象
        if ($poll && $edit) {
            $pdo = $db->prepare('update `activities` set `updated` = :edited where `id` = :id and `updated` < :edited');
            $pdo->execute([':id' => $activity['id'], ':edited' => $edited]);
            $edit = (bool)$pdo->rowCount();
        }
        // 只有票数变了的那种，本站根本不存票数，没有要写进本地副本的东西
        if ($edit) {
            $pdo = $db->prepare('update `announces` set `summary` = :summary, `content` = :content where `activity` = :activity');
            $pdo->execute([':activity' => $activity['id'], ':summary' => $summary, ':content' => $content]);
        }
        // 转不出去的原因 Club_Relay_Allow 已经记过一行，编辑还得说明本地副本仍然更新了
        if (!Club_Relay_Allow($jsonld, $input, $id, $what)) {
            if ($edit) Club_Log_Event('info', 'update applied locally, not relayed', $vars);
            return true;
        }
        $relayed = $clubs && Club_Push_Activity($clubs, $input, false, false, $poll ? 'Update-poll' : 'Update-relay', $activity['shared_inbox']);
        Club_Log_Event($relayed ? 'info' : 'warning', $what.($relayed ? ' relayed' : ' not relayed'), $vars);
        return true;
    });
}

function Club_Follow_Process($jsonld) {
    global $db, $base;
    if (!($club = Club_Object_Name($jsonld['object'] ?? ''))) return Club_Inbox_Skip('follow target is not a club url',
        ['actor' => $jsonld['actor'], 'object' => Club_Object_Id($jsonld['object'] ?? '')]);
    if (!($actor = Club_Get_Actor($jsonld['actor']))) return Club_Inbox_Unresolved($jsonld['actor']);
    return Club_DB_Transaction('follow inbox', function () use ($db, $base, $club, $actor, $jsonld) {
        // 对方重发 Follow 是常态，撞唯一键时保留原记录
        $pdo = $db->prepare('insert ignore into `followers`(`cid`,`uid`,`timestamp`) select `cid`, :uid as `uid`, :timestamp as `timestamp` from `clubs` where `name` = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':timestamp' => time()]);
        $pdo = $db->prepare('select f.id from `followers` as f left join `clubs` as `c` on f.cid = c.cid where f.uid = :uid and c.name = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid']]);
        $follow_id = $pdo->fetch(PDO::FETCH_COLUMN, 0);
        $club_url = $base.'/club/'.$club;
        if ($follow_id) {
            $reason = null;
            $queued = Club_Push_Activity($club, [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $club_url.'#accepts/follows/'.$follow_id,
                'type' => 'Accept',
                'actor' => $club_url,
                'object' => [
                    'id' => Club_Object_Id($jsonld['id'] ?? ''),
                    'type' => 'Follow',
                    'actor' => $jsonld['actor'],
                    'object' => $club_url
                ]
            ], $actor['inbox'], true, null, false, $reason);
            if (!$queued) {
                // 新 follower 与 Accept 必须一起提交。blacklist 是可恢复状态，503 让对端重投；非法 endpoint/消失的 club 是终局拒绝，回滚但不能制造无限 5xx。
                if ($reason === 'blacklisted') throw new Club_Inbox_Deferred('follow Accept target is currently blacklisted');
                throw new Club_Inbox_Rejected('follow Accept enqueue rejected: '.($reason ?: 'unknown'));
            }
            Club_Log_Event('info', 'follow accepted', ['club' => $club, 'actor' => $jsonld['actor']]);
            return true;
        // 上面 insert ignore 之后再查不到，只可能是 clubs 那行没了（并发销群）
        }
        return Club_Inbox_Skip('follow could not be recorded', ['club' => $club, 'actor' => $jsonld['actor']]);
    });
}

function Club_Tombstone_Process($jsonld, $input = null) {
    global $db, $base, $public_streams;
    if (!is_array($jsonld['object'] ?? null) || !($object = Club_Object_Id($jsonld['object']['id'] ?? ''))) return Club_Inbox_Skip('delete without object id', ['actor' => $jsonld['actor']]);
    // Delete 的 id 只拿来去重，不能当准入条件：activity 的 id 在规范里是 SHOULD，GoToSocial 一类实现会省掉，拿它当准入条件的话删嘟会静默失效。
    // 更隐蔽的是有的实现拿被删对象的 URI 当 activity id，而 activities.object 的唯一键是全表共用的，那样去重查询会命中帖子自己的 Create 记录，同样静默跳过。
    // 统一补成 <对象>#delete 两种都躲开，这也正是 Mastodon 自己用的形式
    $id = Club_Object_Id($jsonld['id'] ?? '');
    if ($id === '' || $id === $object) $id = $object.'#delete';
    // 被限流拦下的帖子没有 Announce 可撤，但可能有回复它的提醒，这一步要先做
    Club_Notice_Delete($object, $jsonld['actor']);
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $id]);
    if ($pdo->fetch(PDO::FETCH_ASSOC)) Club_Inbox_Skip('delete already processed', ['object' => $object, 'key' => $id]);
    else {
        // join users 是为了限定只有原作者能撤自己的帖，否则谁都能替别人删
        $pdo = $db->prepare('select a.id, a.uid, a.clubs, a.object, a.timestamp, u.shared_inbox from `activities` `a` join `users` `u` on a.uid = u.uid where a.object = :object and u.actor = :actor');
        $pdo->execute([':object' => $object, ':actor' => $jsonld['actor']]);
        if ($activity = $pdo->fetch(PDO::FETCH_ASSOC)) {
            return Club_DB_Transaction('delete inbox', function () use ($db, $base, $public_streams, $jsonld, $input, $object, $id, $activity) {
            // 撤销记录同样靠唯一键，防止重复投递触发两次 Undo
            $pdo = $db->prepare('insert ignore into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
            $pdo->execute([':uid' => $activity['uid'], ':type' => 'Delete', ':clubs' => $activity['clubs'], ':object' => $id, ':timestamp' => time()]);
            if (!$pdo->rowCount()) return Club_Inbox_Skip('delete claimed by a concurrent delivery', ['object' => $object]);
            // 作者和「本站转发过」这两条闸门由上面那次 join users 的查询把住，这里只差签名
            $relay = isset($input) && Club_Relay_Allow($jsonld, $input, $object, 'Delete');
            $clubs = json_decode($activity['clubs'], 1) ?: [];
            foreach ($clubs as $club) {
                $club_url = $base.'/club/'.$club;
                Club_Push_Activity($club, [
                    '@context' => 'https://www.w3.org/ns/activitystreams',
                    'id' => $club_url.'/activity#'.$activity['id'].'/undo',
                    'type' => 'Undo',
                    'actor' => $club_url,
                    'to' => [$public_streams],
                    'object' => [
                        'id' => $club_url.'/activity#'.$activity['id'].'/announce',
                        'type' => 'Announce',
                        'actor' => $club_url,
                        'published' => gmdate('Y-m-d\TH:i:s\Z', $activity['timestamp']),
                        'to' => [$club_url.'/followers'],
                        'cc' => [
                            $jsonld['actor'],
                            $public_streams
                        ],
                        'object' => $activity['object']
                    ]
                ]);
            }
            // Undo 只撤掉群组那条转嘟，跨实例的关注者本地那份原帖会留成孤儿（作者不在他们那儿，谁也不会再来删它）。原始 Delete 一并转出去才能真正清掉。
            // 排在全部 Undo 之后：不验 LD 签名的实现只认得 Undo，先让它落地。原始报文对这些群组是同一包，一次入队覆盖它们的关注者并集，逐个群组扇出会让同时关注两个的实例收到两遍
            if ($relay && $clubs) Club_Push_Activity($clubs, $input, false, false, 'Delete-relay', $activity['shared_inbox']);
            Club_Log_Event('info', 'announce revoked', ['object' => $object, 'clubs' => $clubs, 'delete relayed' => $relay]);
            $pdo = $db->prepare('delete from `announces` where `activity` = :activity');
            $pdo->execute([':activity' => $activity['id']]);
            return true;
            });
        // 被限流拦下的帖子本来就没转发过，走到这里是正常的；但删嘟没生效时，这条是唯一能区分「没转发过」和「作者对不上」的线索，不记就只能靠猜
        } else Club_Log_Event('info', 'delete has no announce to revoke', ['actor' => $jsonld['actor'], 'object' => $object]);
    }
}

function Club_Undo_Process($jsonld) {
    global $db;
    if (!is_array($jsonld['object'] ?? null)) return Club_Inbox_Skip('undo without an embedded object', ['actor' => $jsonld['actor']]);
    switch ($type = $jsonld['object']['type'] ?? '') {
        case 'Follow':
            if (!($club = Club_Object_Name($jsonld['object']['object'] ?? ''))) return Club_Inbox_Skip('unfollow target is not a club url', ['actor' => $jsonld['actor']]);
            $pdo = $db->prepare('delete from `followers` where `cid` in (select cid from `clubs` where `name` = :club) and `uid` in (select uid from `users` where `actor` = :actor)');
            $pdo->execute([':club' => $club, ':actor' => $jsonld['actor']]);
            // 删了 0 行是常态（对端重发 Undo、或从没关注过），但排查掉关注时要能分清
            Club_Log_Event($pdo->rowCount() ? 'info' : 'debug', 'unfollow, '.$pdo->rowCount().' row(s)', ['club' => $club, 'actor' => $jsonld['actor']]);
            break;
        default: Club_Inbox_Skip('no handler for undo of type', ['type' => $type, 'actor' => $jsonld['actor']]);
    }
}

function Club_Get_OrderedCollection($id, $arr = []) {
    $arr = array_merge(['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $id, 'type' => 'OrderedCollection', 'totalItems' => 0], $arr);
    // Pleroma 2.5.5 解析 featured 时缺 orderedItems 会 FunctionClauseError，把拉取 actor 的请求整个变成 500，空集合一律显式带上空数组；有分页的交给 first 那一页
    if (!$arr['totalItems'] && !isset($arr['first'])) $arr[$arr['type'] === 'Collection' ? 'items' : 'orderedItems'] = [];
    Club_Json_Output($arr, 2);
}

// 游标是「时间戳.自增id」两段，同一秒内有多条也能稳定定位
function Club_Cursor_Parse($cursor) {
    // ?max[]=1 这种数组参数直接挡掉，否则强制转字符串会报警告
    return is_scalar($cursor) && preg_match('/^(\d+)\.(\d+)$/', (string)$cursor, $m) ? [(int)$m[1], (int)$m[2]] : false;
}

// AP 里的 id 字段可以直接是字符串，也可以是内嵌对象里的 id；对端还可能塞数组或数字进来
function Club_Object_Id($object) {
    if (is_array($object)) $object = $object['id'] ?? '';
    return is_string($object) ? $object : '';
}

function Club_Object_Name($object) {
    if (count($parts = explode('/club/', Club_Object_Id($object))) < 2) return false;
    return explode('/', $parts[1])[0];
}

// HTTP/2 的响应头名全是小写，按名字取头要忽略大小写
function Club_Header_Get($headers, $name) {
    foreach ((array)$headers as $k => $v) if (strcasecmp($k, $name) === 0) return $v;
    return '';
}

// Location 可以是相对地址，拼回绝对地址才能接着做内网检查
function Club_Url_Absolute($url, $base) {
    if (preg_match('#^https?://#i', $url)) return $url;
    if (empty($parts = parse_url($base)) || empty($parts['host'])) return '';
    if (substr($url, 0, 2) === '//') return $parts['scheme'].':'.$url;
    $root = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    if (substr($url, 0, 1) === '/') return $root.$url;
    $path = $parts['path'] ?? '/';
    return $root.substr($path, 0, strrpos($path, '/') + 1).$url;
}

function Club_IP_Matches($packed, $network, $bits) {
    $base = @inet_pton($network);
    if ($base === false || strlen($packed) !== strlen($base)) return false;
    $bytes = (int)floor($bits / 8); $tail = $bits % 8;
    if ($bytes && substr($packed, 0, $bytes) !== substr($base, 0, $bytes)) return false;
    if (!$tail) return true;
    $mask = (0xff << (8 - $tail)) & 0xff;
    return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
}

// 只允许普通公网单播。PHP 的 NO_PRIV_RANGE/NO_RES_RANGE 没覆盖共享地址、benchmark、multicast、deprecated IPv6 和 NAT64 等段，不能作为 SSRF 边界。
function Club_IP_Public($ip) {
    if (!is_string($ip) || ($packed = @inet_pton($ip)) === false) return false;
    if (strlen($packed) === 4) {
        foreach ([
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10],
            ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
            ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24],
            ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
            ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]
        ] as $range)
            if (Club_IP_Matches($packed, $range[0], $range[1])) return false;
        return true;
    }
    if (strlen($packed) !== 16 || !Club_IP_Matches($packed, '2000::', 3)) return false;
    // 2000::/3 中仍有协议专用、文档和过渡地址；一律保守拒绝。
    foreach ([['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20]] as $range) if (Club_IP_Matches($packed, $range[0], $range[1])) return false;
    return true;
}

// 只放行公网 http(s)：actor、keyId、inbox 都是对端给的，不挡的话伪造一个签名就能让本站去访问 127.0.0.1、云元数据服务之类的内网目标。
// 三态：IP 列表 = 可投的公网地址 / false 一个公网地址都没有或协议不对，该拦 / null 解析不出来，什么都没证明。返回的是筛过的地址，调用方必须把它钉给 curl
function Club_Url_Public($url) {
    $parts = parse_url((string)$url);
    if (empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) return false;
    // 域名要先解析成 IP 再判断，否则内网地址套个域名就绕过去了
    $host = trim($parts['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    // 「解析失败」和「对端指向内网」是两回事，混成同一个 false 的话，本地 DNS 抽一次风就要报一堆 SSRF warning。
    // 至于这次失败该不该算对端的账，这里判不了，交给调用方问 Club_Resolver_Deferred() 和 Club_Resolver_Healthy()
    elseif (!($ips = Club_Url_Resolve($host))) return null;
    // 剔掉私网和保留段，剩下的公网地址才交出去。剔掉就等于对 curl 不存在，所以只拦坏地址不牵连整家：把虚拟机的 fe80:: 发到公网 DNS 的实例并不少见，一条垃圾 AAAA 不该让一个 A 记录正常的对端整个失联。
    // 这道防线依赖「出网必钉地址」，新增出网路径时必须一起把 $ips 带上，否则 curl 自己解析一遍就把这里剔掉的地址捡了回去
    $public = [];
    foreach ($ips as $ip) if (Club_IP_Public($ip)) $public[] = $ip;
    $public = array_values(array_unique($public));
    if (!$public) return false;
    // 剔掉了什么不能不留痕：对端解析结果变成内网是它自己配错还是被劫持，事后要能查
    if (count($public) < count($ips)) Club_Log_Event('debug', 'dropped non-public addresses', ['host' => $host, 'kept' => implode(',', $public), 'dropped' => implode(',', array_diff($ips, $public))]);
    // 是不是公网每次都由 IP 现算，不缓存这个结论：它是 IP 的纯函数，而 IP 已经缓存过了。单独存一份就是第二个时钟，放行的有效期可能超过 IP 的，安全窗口会悄悄漂长
    return $public;
}

// A 查到了就不查 AAAA 的话，对端只要 A 摆个公网地址、AAAA 指 ::1，curl 默认优先走 v6 就绕过了检查，所以两种记录都要查，取并集一起校验。缓存只有 dns 表这一层，几十个 worker 加 fpm 共用。
// 别再往进程内加一层：一次推送几千个对端、每个进程只经手其中一小份，下一条推送绝大多数 host 还是冷的，白占内存还多一个时钟；而查一次主键比查一次 DNS 快两个数量级。返回地址列表；
// [] 是查过了确实没有，false 是这一轮压根没查成，后者不能算对端的账
function Club_Url_Resolve($host, $ttl = 300, $miss = 60, $stale = 3600) {
    $now = time(); Club_Resolver_Deferred(false);
    $row = Club_Resolver_Read($host);
    // 负结果的 TTL 短得多：域名可能刚续费、刚换完 NS，一分钟后就该再试一次
    if ($row && $row['checked_at'] > $now - ($row['ips'] === '' ? $miss : $ttl)) return Club_Resolver_Cached($host, $row, $now, $miss);
    $stock = $row && $row['ips'] !== '' && $row['checked_at'] > $now - $stale ? $row['ips'] : null;
    // 几十个进程同时发现同一个 host 过期会一起去查 DNS。抢到刷新锁的那个才真去解析，其余的拿旧值先顶一轮；连旧值都没有的只能等赢家提交。
    // 窗口要盖得住最坏那次解析，再留点余量给前后几次数据库往返；30 秒是下限，免得配置调小之后一个卡住的进程把这个 host 锁得太久
    if (!Club_Resolver_Claim($host, $now, $token = Club_Token(), max(30, Club_Resolver_Budget() + 10))) {
        Club_Stat('dns_contention');
        if (isset($stock)) {
            Club_Stat('dns_stale');
            Club_Log_Event('debug', 'dns refresh deferred, another worker holds the lock', ['host' => $host, 'age' => $now - $row['checked_at'], 'ip' => $stock]);
            return explode(',', $stock);
        }
        // 冷缓存又没抢到锁。自己再查一遍就把「同一个 host 只查一次」作废了，32 个进程会一起打 UDP；赢家通常一秒内就提交，等一小会儿再重读划算得多。抖动是为了别在同一刻齐步回来。
        // 负结果的新鲜窗口仍然是 $miss：拿一条上面刚判过期的负缓存当赢家的结论，等于让对端为本站这一轮没查成的 DNS 白记一次 unresolved 和一整套退避
        usleep(mt_rand(200000, 1000000)); $now = time();
        if (($row = Club_Resolver_Read($host)) && $row['checked_at'] > $now - ($row['ips'] === '' ? $miss : $ttl)) return Club_Resolver_Cached($host, $row, $now, $miss);
        Club_Stat('dns_deferred'); Club_Resolver_Deferred(true);
        Club_Log_Event('debug', 'dns refresh deferred, no committed result yet', ['host' => $host]);
        return false;
    }
    $ips = Club_Resolver_Query($host);
    // 一家 resolver 都没答上来。这什么都没证明，绝不能写负缓存：写了就是把本站这一侧的故障记成「对端把域名撤了」，它名下几千行队列跟着按 unresolved 退避。
    // 而 Store 还会重置 checked_at，那道 1 小时的安全边界会跟着续命，域名真改指到内网时我们还在拿旧地址放行。所以只放掉刷新锁，有旧值先顶一轮，没有就交给调用方按 local-dns 处置
    if ($ips === false) {
        Club_Resolver_Release($host, $token);
        if (isset($stock)) {
            Club_Stat('dns_stale');
            Club_Log_Event('debug', 'dns lookup failed, reusing cached address', ['host' => $host, 'age' => $now - $row['checked_at'], 'ip' => $stock]);
            return explode(',', $stock);
        }
        Club_Stat('dns_unreachable'); Club_Resolver_Deferred(true);
        Club_Log_Event('warning', 'dns lookup failed, no resolver answered', ['host' => $host]);
        return false;
    }
    // 空结果是权威答复「没有这种记录」，跟上面那种问不到是两回事，照常落库。换掉系统 resolver 之前这两种混在一个 false 里分不开，只能一律留着旧值；现在分得开了，NXDOMAIN 就该让旧地址过期。
    // 负结果也要落库。不记的话，一家解析不出来的对端，它名下每一行都要重查一遍，几千行乘以两次出网查询，足够把 resolver 那边的配额打满、把好域名也拖成解析失败
    if (!Club_Resolver_Store($host, $token, $ips ? implode(',', $ips) : '', time())) {
        // 真实查询超出了刷新窗口，锁已经被别人接走：这份结果是旧的，覆盖新 owner 提交的地址等于把安全检查退回上一轮，只能丢掉自己的结果去读赢家的
        Club_Stat('dns_stale_store');
        Club_Log_Event('debug', 'stale dns result discarded', ['host' => $host, 'token' => $token]);
        // 赢家可能也还没提交，读回来的仍是过期行；超出窗口就不能再用了。
        // 旧正缓存可以顶到 stale，负缓存只认 $miss —— 它不是可以续命的地址，是一个「查了没有」的结论，过了期就该重查而不是拿去判对端失败
        if (($row = Club_Resolver_Read($host)) && $row['checked_at'] > time() - ($row['ips'] === '' ? $miss : $stale)) return Club_Resolver_Cached($host, $row, time(), $miss);
        Club_Resolver_Deferred(true);
        return false;
    }
    if ($ips) { Club_Stat('dns_positive'); Club_Resolver_Healthy(true); return $ips; }
    Club_Stat('dns_negative');
    Club_Log_Event('debug', 'dns lookup returned no records', ['host' => $host]);
    return [];
}

// 命中已提交缓存的共同出口：正缓存顺带给本站 DNS 记一笔实据，但成功的时刻是写缓存那个进程的，不是此刻
function Club_Resolver_Cached($host, $row, $now, $miss) {
    if ($row['ips'] !== '') {
        Club_Stat('dns_positive'); Club_Resolver_Healthy($row['checked_at']);
        return explode(',', $row['ips']);
    }
    Club_Stat('dns_negative');
    Club_Log_Event('debug', 'dns lookup skipped, negative cache still fresh', ['host' => $host, 'age' => $now - $row['checked_at'], 'retry' => $row['checked_at'] + $miss - $now]);
    return [];
}

// 上一次解析是不是「没查成」而不是「查了没有」。前者要判 local-dns：这一轮既没问过 DNS 也没问过对端，记在对端头上会让它白等一整套退避阶梯
function Club_Resolver_Deferred($set = null) {
    static $deferred = false;
    if (isset($set)) $deferred = (bool)$set;
    return $deferred;
}

// 一次查不到，到底是对端注销了域名、还是本站 DNS 坏了？单看这一次分不出来。但本站 DNS 坏了不会只坏一个 host：最近还成功解析过别的域名，就说明出口是通的，那这次查不到就是对端自己的事，该照常记失败；
// 反之才是我们的问题，不能算在对端头上。$mark 可以传时间戳：拿的是别的进程的成功记录，那是实据，但时刻是它的
function Club_Resolver_Healthy($mark = false, $window = 600) {
    global $db, $config; static $last = 0;
    if ($mark !== false) { $last = max($last, $mark === true ? time() : (int)$mark); return true; }
    if ($last > time() - $window) return true;
    // 本进程手上没有实据就问全站。
    // 只靠进程内那个 static 的话，刚 fork 出来的 worker 它是 0，接手的头几条只要正好是解析不出来的对端，就会一口咬定本站 DNS 坏了 —— 解析结果挪进 dns 表之后真解析本来就少，
    // 这个信号单靠一个进程攒不起来。空 ips 是负缓存，它证明的是「查了没有」，不能拿来当本站 DNS 通着的实据。只在准备下结论前跑，正常时随便扫几行就命中；真出口断了才会扫满，而那时也确实该断言
    $pdo = $db->prepare('select `checked_at` from `dns` where length(`ips`) > 0 and `checked_at` > :window limit 1');
    $pdo->execute([':window' => time() - $window]);
    if ($resolved = $pdo->fetch(PDO::FETCH_COLUMN, 0)) { $last = max($last, (int)$resolved); return true; }
    // 全站都没有新鲜的成功记录，不等于解析坏了 —— 也可能只是这段时间没人投递过。
    // 拿沉默当反面证据的话，闲下来之后每个死域名都会被判成本站 DNS 的锅，而那条路既不记失败也不放弃，行每 300 秒重投一次，永远死不掉。所以自己去解析一个必然存在的域名：本站自己的。
    // 它都解析不动才真是我们的问题。看的是 $last 有没有被标上而不是返回值：真查通了和 stale 兜底拿回旧地址，后者恰恰说明这会儿解析不动。缓存也顺带把频率压住，300 秒内不会再探第二次
    Club_Url_Resolve($config['base']);
    return $last > time() - $window;
}

function Club_Resolver_Read($host) {
    global $db;
    $pdo = $db->prepare('select `ips`, `checked_at`, `lock_until` from `dns` where `host` = :host');
    $pdo->execute([':host' => $host]); Club_Stat('scheduler_db_ops');
    return $pdo->fetch(PDO::FETCH_ASSOC) ?: null;
}

// 抢刷新权。行还不存在时 insert ignore 建出来，插进去的那个自然就是抢到的。
// 锁和缓存分成两组列：抢到就先推 checked_at 的话，这次解析要是失败了，旧 IP 的 stale 窗口会跟着一起续命，那道 1 小时的安全边界就守不住了。
// $window 由调用方按 Club_Resolver_Budget 算好传进来，这里的默认值只是个兜底
function Club_Resolver_Claim($host, $now, $token, $window = 30) {
    global $db;
    $pdo = $db->prepare('update `dns` set `lock_token` = unhex(:token), `lock_until` = :until where `host` = :host and `lock_until` <= :now');
    $pdo->execute([':token' => $token, ':until' => $now + $window, ':host' => $host, ':now' => $now]);
    Club_Stat('scheduler_db_ops');
    if ($pdo->rowCount()) return true;
    $pdo = $db->prepare('insert ignore into `dns`(`host`, `lock_until`, `lock_token`) values (:host, :until, unhex(:token))');
    $pdo->execute([':host' => $host, ':until' => $now + $window, ':token' => $token]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

// 凭 token 提交解析结果。查询超出刷新窗口锁就会被别人接走，那之后这份结果已经是旧的：条件不匹配时一行都不写，让调用方去读赢家提交的地址
function Club_Resolver_Store($host, $token, $ips, $now) {
    global $db;
    $pdo = $db->prepare('update `dns` set `ips` = :ips, `checked_at` = :now, `lock_until` = 0, `lock_token` = null where `host` = :host and `lock_token` = unhex(:token)');
    $pdo->execute([':host' => $host, ':token' => $token, ':ips' => $ips, ':now' => $now]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

// 查失败又不该写负缓存时放手。同样只按 token 放：锁已经易主的话，清掉的就是新 owner 的租约，两个进程会同时对一个 host 发起真实查询
function Club_Resolver_Release($host, $token) {
    global $db;
    $pdo = $db->prepare('update `dns` set `lock_until` = 0, `lock_token` = null where `host` = :host and `lock_token` = unhex(:token)');
    $pdo->execute([':host' => $host, ':token' => $token]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

// 配置里的 resolver 列表，没配就用内置这两家。老部署升级上来时 config.php 里没有这一节，缺省不能是空列表 —— 那样全站一个域名都解析不动
function Club_Resolver_List() {
    global $config;
    // 预设带 ip：这两组是多年不变的 anycast 地址，钉上去就完全不碰系统 resolver，本机 DNS 坏掉也不影响出网。
    // 钉地址不动摇 TLS：CURLOPT_RESOLVE 换掉的只是这个 host:port 的解析结果，SNI 和证书校验用的仍然是 URL 里的域名。
    // 但域名要选真名副其实的那个 —— one.one.one.one 和 dns.google 自己的 A/AAAA 就是下面这些地址，而 cloudflare-dns.com 的真实记录在 104.16 那段 CDN anycast 上，
    // 把它钉到 1.1.1.1 当下能连通，靠的是那台机器上的证书恰好也覆盖这个名字，对方一调整就是整站解析不动
    return $config['dns']['resolver'] ?? [['url' => 'https://one.one.one.one/dns-query', 'ip' => ['1.1.1.1', '1.0.0.1', '2606:4700:4700::1111', '2606:4700:4700::1001']],
        ['url' => 'https://dns.google/resolve', 'ip' => ['8.8.8.8', '8.8.4.4', '2001:4860:4860::8888', '2001:4860:4860::8844']]];
}

// 一次解析最坏能花多久：每家顺序问 A 和 AAAA（A 就失败的话直接换下一家，所以是乘二不是乘查询数），一家问不出来再换下一家。
// 刷新锁的窗口由它算出来，不能写死：窗口短于这个预算的话，第一个进程还没问完锁就到期，第二个进程接手重问一遍，而它同样问不完 —— resolver 出故障的那一刻恰好是查询被放大的时刻
function Club_Resolver_Budget() {
    global $config;
    return max(1, (int)($config['dns']['timeout'] ?? 5)) * 2 * max(1, count(Club_Resolver_List()));
}

// 一次 DoH 查询。用 JSON 格式（application/dns-json）而不是 wireformat：后者要自己写报文编解码，而这里只要几个地址；两家的参数名和返回结构一致，换一家不用换解析。
// 三态，调用方必须分开处置：
//   数组   查到的地址，空数组是权威答复「没有这种记录」
//   null   resolver 答了 SERVFAIL —— 这个域名解析不出来，是对端的故障
//   false  这家没答上来（连不上、4xx/5xx、报文坏、REFUSED），什么都没证明，是本站这一侧的事
function Club_Resolver_DoH($resolver, $host, $type) {
    global $config;
    static $curl = null;
    // 不复用 ActivityPub_CURL 那个全局句柄：那边的调用方在请求之后还要读 responseHeaders 和 httpStatusCode 判跳转，套进同一个句柄会把判断依据冲掉
    if (!isset($curl)) { $curl = new Curl(); $curl->setHeader('Accept', 'application/dns-json'); $curl->setFollowLocation(false); $curl->setOpt(CURLOPT_PROTOCOLS, CURLPROTO_HTTPS); }
    // 期限由 curl 自己执行，不经过 PHP 的检查点，两个 SAPI 都算数 —— 这正是换掉系统 resolver 的理由。
    // 必须夹到 1 以上：libcurl 把 0 定义成「永不超时」，配置里手滑写个 0 或负数就等于把刚拆掉的无限等待原样装回来，而那种故障要等到线上卡住才发作
    $curl->setTimeout(max(1, (int)($config['dns']['timeout'] ?? 5)));
    $curl->setConnectTimeout(max(1, (int)($config['dns']['connect-timeout'] ?? 3)));
    // 钉住 resolver 自己的地址。没配 ip 就交给 curl 去解析：那仍然是系统 resolver，但走在 curl 里，超时管得住，不像 gethostbynamel 那样能把整个进程挂死。
    // 不像 ActivityPub_CURL 那样每次先用 '-' 撤掉上一条：那边钉的 host 是对端域名，一个长期进程会经手几千个，这边钉的只有 resolver 列表里那两三个，条目数是常数，攒不起来
    if (!empty($resolver['ip']) && !empty(($parts = parse_url($resolver['url']))['host'])) {
        $ips = $resolver['ip'];
        foreach ($ips as $i => $ip) if (strpos($ip, ':') !== false) $ips[$i] = '['.$ip.']';
        $curl->setOpt(CURLOPT_RESOLVE, [$parts['host'].':'.($parts['port'] ?? 443).':'.implode(',', $ips)]);
    }
    $json = json_decode((string)$curl->get($resolver['url'].'?name='.urlencode($host).'&type='.$type), 1);
    // 传输失败和 4xx/5xx 都由 $curl->error 兜住（见 Curl::exec）。错误页的正文一般也过不了 Club_Resolver_Answer 那关，但那是巧合不是保证，状态码该自己看
    $answer = $curl->error ? false : Club_Resolver_Answer($json, $type);
    // SERVFAIL 是对端域名的毛病，这家 resolver 本身是好的，别混进 warning 里去污染本站的故障视图
    if ($answer === null) Club_Log_Event('info', 'doh reports the domain fails to resolve', ['resolver' => $resolver['url'], 'host' => $host, 'type' => $type]);
    elseif ($answer === false)
        Club_Log_Event('warning', 'doh query failed', ['resolver' => $resolver['url'], 'host' => $host, 'type' => $type,
            'http' => $curl->httpStatusCode, 'status' => is_array($json) ? ($json['Status'] ?? '?') : '?', 'error' => $curl->errorMessage]);
    return $answer;
}

// DoH 报文的解析，单拎出来是为了能不出网地验：这段是整套东西里最该有检查的一节，而本机没有 curl 扩展，连不上就什么都跑不了。三态，含义同上
function Club_Resolver_Answer($json, $type) {
    // 报文根本不成形，这家没答上来
    if (!is_array($json) || !isset($json['Status'])) return false;
    // 2 = SERVFAIL。这是一次货真价实的 DNS 答复，说的是「这个域名解析不出来」—— 权威服务器挂了、DNSSEC 验不过都落在这里，是对端的事不是我们的事。
    // 混进 false 的话这种对端会永远走 local-dns：不记 fails、不退避、进不了黑名单，队列每五分钟重投一次，活动还在继续入队，只增不减。
    // 但一家的判断不能定罪，返回 null 让调用方拿其余 resolver 复核
    if ($json['Status'] === 2) return null;
    // 0 是 NOERROR，3 是 NXDOMAIN，这两种是答案。其余（REFUSED、NOTIMP、FORMERR）说的是这家不肯或没法替我们查 —— 限流、客户端被拦、查询本身不合法，都是本站这一侧的事，换一家问
    if ($json['Status'] !== 0 && $json['Status'] !== 3) return false;
    // TC 是「这份答复被截断了」。走 HTTPS 没有 512 字节那道限制，正常不会置位；真置了就说明记录不全，当没答上来换一家 —— 拿半份答案去写负缓存是这里最坏的结果
    if (!empty($json['TC'])) return false;
    // Answer 在但不是数组：报文坏了，不是「没有记录」。不挡的话 foreach 会对着一个标量报 warning 然后一圈都不转，函数照样返回空数组，一份垃圾响应就成了对端的罪证
    if (isset($json['Answer']) && !is_array($json['Answer'])) return false;
    // NXDOMAIN 是终局否定，这个名字不存在，底下不用看了。带地址记录的 NXDOMAIN 是自相矛盾的报文，那个地址下一步就要钉给 curl，宁可不要 ——
    // 而 Answer 里跟着 CNAME 是合规的（链走到一半、末端不存在），所以也不能因为 Answer 非空就当它是正答复
    if ($json['Status'] === 3) return [];
    // Answer 里会夹着 CNAME 链，只认问的那一种；1 = A，28 = AAAA。
    // 只按 type 筛，不按 name 筛：CNAME 之后的地址记录挂的是链尾的名字而不是问的那个，按 name 对齐会把所有用 CNAME 的对端一律判成没有地址
    $want = $type === 'AAAA' ? 28 : 1; $ips = []; $seen = 0; $junk = false;
    foreach ($json['Answer'] ?? [] as $rr) {
        // 记录本身不是对象：两家都不会这么答，这份报文就是坏的
        if (!is_array($rr)) { $junk = true; continue; }
        // type 按数值比，不按类型比：两家现在都给数字，但一个把它序列化成字符串的 resolver 会让每条记录都被跳过，好域名整片写进负缓存
        if ((int)($rr['type'] ?? 0) !== $want) continue;
        $seen++;
        // 地址来自站外，而它下一步就是 SSRF 检查的输入，格式必须自己验一遍，不能拿去当 IP 用
        if (filter_var($rr['data'] ?? '', FILTER_VALIDATE_IP)) $ips[] = $rr['data'];
    }
    // 报文里有不成形的记录，或者有想要的记录却一个地址都不成形：这家答的东西没法用，当没答上来换一家。
    // 返回空数组的话，一份坏报文就会被写成「这个域名没有地址」的负缓存，罪算在对端头上。夹着 CNAME 却没有地址记录是另一回事，那是合规的「没有这种记录」，照常返回空数组
    if ($junk || ($seen && !$ips)) return false;
    return $ips;
}

// 真实 A/AAAA 查询的唯一出口。不走系统 resolver：它在本进程里跑又没有应用层硬期限，卡住就是一个 worker 槽位或一个 fpm 子进程永久占着，
// 而 PHP 侧任何期限都拦不住它 —— max_execution_time 计的是 CPU 时间，阻塞时根本不走；SIGALRM 处理器要等它自己返回才轮得到派发。DoH 走 curl，超时由 curl 执行，这才是有界的。
// 命中缓存和没抢到锁的都不写日志：那两种情况根本没发查询。返回 [] 是问过了确实没有（含每一家都答上话、且有人说 SERVFAIL），false 是没问成
function Club_Resolver_Query($host) {
    static $seq = 0;
    $ref = getmypid().'-'.++$seq; $start = microtime(true); $ips = false; $used = ''; $servfail = 0; $silent = 0;
    Club_Log_Event('debug', 'resolver_query_started', ['sapi' => PHP_SAPI, 'slot' => Club_Worker_Slot(), 'pid' => getmypid(), 'host' => $host, 'query_ref' => $ref]);
    // 按顺序问，只有「没答上来」和 SERVFAIL 才换下一家；答了 NXDOMAIN 是答案，再问一家等于拿第二家的意见推翻第一家
    foreach (Club_Resolver_List() as $resolver) {
        // A 和 AAAA 都要，理由见 Club_Url_Resolve：只查一种的话，对端摆个公网 A 配一条 ::1 的 AAAA 就绕过了内网检查
        // 两族一律各问一次，A 的结果不作为跳过 AAAA 的理由：SERVFAIL 本来就是按查询类型给的（A 的记录集验不过签、权威只在 A 上出故障都可能），
        // 而 false 里除了「连不上」还混着 500、REFUSED、报文坏，这些都证明不了下一次 AAAA 也会失败 —— 漏掉的正是只有 v6 的对端。
        // 代价只是对着一家坏掉的 resolver 多等一个超时，而刷新窗口的预算（Club_Resolver_Budget）从一开始就是按每家两次算的，收得住
        $a = Club_Resolver_DoH($resolver, $host, 'A');
        if ($a === null) { $servfail++; $a = false; }
        elseif ($a === false) $silent++;
        $aaaa = Club_Resolver_DoH($resolver, $host, 'AAAA');
        if ($aaaa === null) { $servfail++; $aaaa = false; }
        elseif ($aaaa === false) $silent++;
        $found = array_values(array_unique(array_merge(is_array($a) ? $a : [], is_array($aaaa) ? $aaaa : [])));
        // 拿到地址就用，哪怕另一族没答上来 —— 钉给 curl 的只有查到的这些，少钉一族不会给内网检查开口子。
        // 一个地址都没有的时候，只有两族都给出了明确答复才算「这个域名没有地址」；有一族没结论就换下一家，否则一次 A 或 AAAA 的抖动就能把好域名写进负缓存
        if (!$found && ($a === false || $aaaa === false)) continue;
        $ips = $found; $used = $resolver['url'];
        break;
    }
    // 判给对端要两个条件同时成立：有人明确答了 SERVFAIL，而且没有任何一家是「没问成」。
    // 少一家没答上话就等于少一票复核，拿仅剩的一票定罪的话，本站到另一家的网络不通就足以让一个好端端的对端被记成解析失败，持续下去还会进黑名单。
    // 全票 SERVFAIL 才落成空数组，走 NXDOMAIN 那条路：写负缓存、投递记 unresolved，该退避退避该拉黑拉黑。
    // 反过来也不能一律算本站的：那条 local-dns 路既不记失败也不放弃，每 300 秒重投一次，而新活动还在往 queues 里进，永远清不掉
    if ($ips === false && $servfail && !$silent) { $ips = []; Club_Stat('dns_servfail'); }
    $ms = (microtime(true) - $start) * 1000;
    Club_Stat('dns_queries'); Club_Stat_Sample('resolver_query_ms', $ms);
    Club_Log_Event('debug', 'resolver_query_finished', ['query_ref' => $ref, 'ms' => round($ms), 'resolver' => $used, 'found' => $ips === false ? -1 : count($ips)]);
    return $ips;
}

// dns 只是缓存，没有队列引用这一说：长期没人问就删掉，最多让下次重查一次。刷新锁还在有效期内的不能删，否则 Store 会插回一行没人认领的缓存
function Club_Resolver_Cleanup($ttl = 86400, $limit = 200) {
    global $db; $now = time(); $expire = $now - $ttl;
    // 候选只读不锁、删除按主键，理由见 Club_Lease_Pick：沿 checked_at 扫描的 DELETE 会跟 Store 先按 host 主键锁行、再回头改 checked_at 咬成一个环。
    // DELETE 没有半一致读，不匹配的行也要先锁上再判，光靠 lock_until 这个条件躲不开。而 Store 在投递路径上不走 Club_DB_Retry，被选成牺牲者就是一个 worker 停一秒
    $pdo = $db->prepare('select `host` from `dns` where `lock_until` <= :now and `checked_at` <= :expire limit '.(int)$limit);
    $pdo->execute([':now' => $now, ':expire' => $expire]);
    $hosts = $pdo->fetchAll(PDO::FETCH_COLUMN, 0);
    Club_Stat('scheduler_db_ops');
    if (!$hosts) {
        Club_Log_Event('debug', 'dns cache has nothing expired to evict', ['age' => $ttl]);
        return 0;
    }
    // 一条一条按主键删。候选读到手就可能被重新锁上或刷新，两个条件在 delete 里原样重判一遍。
    // 不用 in 列表：那样虽然给的也是主键，但走不走 PRIMARY 仍由优化器的代价估算说了算，而这里要的恰恰是「一定不沿 checked_at 扫」这个保证，单值等值条件才给得起。
    // 顺带每条各自 autocommit，锁不会攒到整批结束
    $pdo = $db->prepare('delete from `dns` where `host` = :host and `lock_until` <= :now and `checked_at` <= :expire');
    $rows = 0;
    foreach ($hosts as $host) {
        $pdo->execute([':host' => $host, ':now' => $now, ':expire' => $expire]);
        $rows += $pdo->rowCount();
    }
    Club_Stat('scheduler_db_ops', count($hosts));
    if (!$rows) {
        Club_Log_Event('debug', 'dns cache entries were relocked or refreshed before eviction', ['candidates' => count($hosts)]);
        return 0;
    }
    Club_Log_Event('debug', 'dns cache entries evicted', ['rows' => $rows, 'age' => $ttl]);
    return $rows;
}

// 领一条 endpoint。所有权必须在解析和出网之前就拿到手：只按 queue 行调度的话，同一个 target 的几千条行会被几十个进程各领一条，一起对同一家发请求。
// 候选读和领取都是 autocommit 的单条语句，不把行锁带进后面的 DNS 和 HTTP
function Club_Endpoint_Claim($now, $lease = 120) {
    global $db; $start = microtime(true);
    // 候选只读不锁，领取按主键 CAS，取锁顺序才跟 completion 一致，理由见 Club_Lease_Pick。
    // 只按 next_at 排序：lease_until 不是等值条件，再往 order by 里加列会跳过 schedule 索引的中间列，退化成 filesort，几万行 endpoint 时每轮都要排一遍
    $pdo = $db->prepare('select `url` from `endpoints` where `next_at` is not null and `next_at` <= :now and `retry_at` <= :now and `lease_until` <= :now order by `next_at` limit 32');
    $pdo->execute([':now' => $now]);
    $candidates = $pdo->fetchAll(PDO::FETCH_COLUMN, 0);
    Club_Stat('endpoint_claim_attempts'); Club_Stat('scheduler_db_ops');
    // 一条到期的都没有，这是真空闲。几十个进程每轮都会走到这里，只记数不记日志
    if (!$candidates) {
        Club_Stat('endpoint_misses');
        Club_Stat_Sample('endpoint_claim_sql_ms', (microtime(true) - $start) * 1000);
        return null;
    }
    // 候选是读到手就可能过期的，领取条件在 UPDATE 里原样重判一遍，由影响行数说了算
    $token = Club_Token();
    $claim = $db->prepare('update `endpoints` set `lease_token` = unhex(:token), `lease_until` = :until'.
        ' where `url` = :url and `next_at` is not null and `next_at` <= :now and `retry_at` <= :now and `lease_until` <= :now');
    $url = Club_Lease_Pick($candidates, $token, function ($url) use ($claim, $token, $now, $lease) {
        $claim->execute([':token' => $token, ':until' => $now + $lease, ':url' => $url, ':now' => $now]);
        Club_Stat('scheduler_db_ops');
        return (bool)$claim->rowCount();
    });
    Club_Stat_Sample('endpoint_claim_sql_ms', (microtime(true) - $start) * 1000);
    // 有活可干却一条都没抢到，跟空闲不是一回事：这说明并发已经压过了可调度的 endpoint 数量，加进程只会让抢输更多。混进 miss 里的话命中率再也读不出这个区别
    if (!isset($url)) {
        Club_Stat('endpoint_claim_races');
        Club_Log_Event('debug', 'endpoint claim lost every candidate it tried', ['candidates' => count($candidates)]);
        return null;
    }
    $pdo = $db->prepare('select `url`, `next_at`, `retry_at`, `fails` from `endpoints` where `lease_token` = unhex(:token)');
    $pdo->execute([':token' => $token]); Club_Stat('scheduler_db_ops');
    if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) {
        // 唯一索引里查不回自己刚写的 token，只可能是时钟或租约被人越过了
        Club_Log_Event('warning', 'endpoint claim lost before read', ['token' => $token]);
        return null;
    }
    $row['token'] = $token;
    Club_Stat('endpoint_claims');
    Club_Stat_Max('endpoint_schedule_lag_s', max(0, $now - (int)$row['next_at']));
    Club_Log_Event('debug', 'endpoint claimed', ['endpoint' => $row['url'], 'token' => $token, 'lag' => max(0, $now - (int)$row['next_at'])]);
    return $row;
}

// 认领只验了 retry_at，黑名单目标是靠 next_at 这个提示避开的，而提示可以过时；租约、退避和黑名单都在这一句里跟着选行条件重判，投递真正被拦住的地方就是这里
function Club_Endpoint_Queue($url, $token, $now) {
    global $db;
    $pdo = $db->prepare('select `q`.`id`, `q`.`tid`, `q`.`target`, `q`.`due_at`, `q`.`retries`, `t`.`type`, `t`.`jsonld`, `c`.`name` as `club` from `endpoints` as `e`'.
        ' join `queues` as `q` on q.target = e.url left join `tasks` as `t` on q.tid = t.tid left join `clubs` as `c` on t.cid = c.cid where `e`.`url` = :url and `e`.`lease_token` = unhex(:token)'.
        ' and `e`.`lease_until` > :now and `e`.`retry_at` <= :now and `q`.`due_at` <= :now and not exists (select 1 from `blacklist` where `target` = :url) order by `q`.`due_at`, `q`.`id` limit 1');
    $pdo->execute([':url' => $url, ':token' => $token, ':now' => $now]); Club_Stat('scheduler_db_ops');
    if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) return null;
    Club_Stat_Max('queue_due_lag_s', max(0, $now - (int)$row['due_at']));
    return $row;
}

// 出网前的最后一道闸：解析可能已经卡过了整段租约。租约到期不单独作废 token；若尚未被接管，旧 owner 可用 CAS 续租，若已被接管 token 必然变化，所以只有影响到一行才算拿到发送权。
// 顺带把出网前提一次查清 —— 退避、queue 还在不在、有没有进黑名单，任何一条不成立都不能发
function Club_Endpoint_Authorize($url, $token, $next, $queue, $lease = 120) {
    global $db; $now = time();
    $pdo = $db->prepare('update `endpoints` set `lease_token` = unhex(:next), `lease_until` = :until where `url` = :url and `lease_token` = unhex(:token) and `retry_at` <= :now'.
        ' and exists (select 1 from `queues` where `id` = :queue and `target` = :url and `due_at` <= :now) and not exists (select 1 from `blacklist` where `target` = :url)');
    $pdo->execute([':url' => $url, ':token' => $token, ':next' => $next, ':queue' => $queue, ':now' => $now, ':until' => $now + $lease]);
    Club_Stat('scheduler_db_ops');
    if ($pdo->rowCount()) return true;
    // 拦下来的原因决定了后面该不该重排这条 endpoint，多查一次值得
    $pdo = $db->prepare('select (select count(*) from `endpoints` where `url` = :url and `lease_token` = unhex(:token)) as `owned`, (select count(*) from `blacklist`'.
        ' where `target` = :url) as `blocked`, (select count(*) from `queues` where `id` = :queue and `target` = :url and `due_at` <= :now) as `queued`');
    $pdo->execute([':url' => $url, ':token' => $token, ':queue' => $queue, ':now' => $now]);
    $why = $pdo->fetch(PDO::FETCH_ASSOC) ?: [];
    Club_Stat('renew_failed'); Club_Stat('scheduler_db_ops');
    Club_Log_Event('debug', 'pre-HTTP token renewal rejected', ['endpoint' => $url, 'token' => $token,
        'owned' => (int)($why['owned'] ?? 0), 'blacklisted' => (int)($why['blocked'] ?? 0), 'queued' => (int)($why['queued'] ?? 0)]);
    return false;
}

// 这条 endpoint 此刻应该排在什么时候：进了黑名单或者一条 queue 都没有是 null，其余是 max(retry_at, min(due_at))。完成、重排和对账都要算它，各写一份的话三处会慢慢漂开，而漂出来的差值恰好没人看得见
function Club_Endpoint_Desired($url, $retry_at) {
    global $db;
    $pdo = $db->prepare('select 1 from `blacklist` where `target` = :url');
    $pdo->execute([':url' => $url]);
    if ($pdo->fetch(PDO::FETCH_COLUMN, 0)) { Club_Stat('scheduler_db_ops'); return null; }
    $pdo = $db->prepare('select min(`due_at`) from `queues` where `target` = :url');
    $pdo->execute([':url' => $url]);
    $due = $pdo->fetch(PDO::FETCH_COLUMN, 0);
    Club_Stat('scheduler_db_ops', 2);
    return isset($due) ? max((int)$retry_at, (int)$due) : null;
}

// 重算调度提示并放掉租约，必须在已经锁住控制行的事务里调用。next_at 偏早不会绕过退避（领取和出网前都还要硬判 retry_at 和黑名单），偏晚才会真耽误投递，所以每次完成都照 queues 重算一遍。
// 并发入队的 upsert 要改这一行就得先等我们的行锁，所以它要么排在前面已经被这次 min 看见，要么排在后面再用 least 把更早的时间写回来，不会被盖掉
function Club_Endpoint_Reschedule($url, $token, $retry_at) {
    global $db; $now = time();
    $next = Club_Endpoint_Desired($url, $retry_at);
    // idle_since 只在「变空」的那一次落时刻，已经空着的保持原值：每次重排都刷新的话空置时长永远回到零，回收的宽限期就再也到不了
    $pdo = $db->prepare('update `endpoints` set `next_at` = :next, `lease_token` = null, `lease_until` = 0, `idle_since` = if(:next is null,'.
        ' if(`idle_since` > 0, `idle_since`, :now), 0) where `url` = :url and `lease_token` = unhex(:token)');
    $pdo->execute([':url' => $url, ':token' => $token, ':next' => $next, ':now' => $now]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

// 领到了却没活可干、或者出网前被拦下：也要按 token 重排一次再放手。就这么让租约挂到自然过期的话，这 120 秒里这条 endpoint 谁都碰不了
function Club_Endpoint_Release($url, $token) {
    global $db; $released = false;
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `retry_at` from `endpoints` where `url` = :url and `lease_token` = unhex(:token) for update');
        $pdo->execute([':url' => $url, ':token' => $token]);
        if ($row = $pdo->fetch(PDO::FETCH_ASSOC)) $released = Club_Endpoint_Reschedule($url, $token, (int)$row['retry_at']);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Log_Event('debug', $released ? 'endpoint released' : 'endpoint release skipped, lease is no longer ours', ['endpoint' => $url, 'token' => $token]);
    return $released;
}

// 退避档位读故障年龄，不读次数：一家挂掉的那一刻几十个 worker 手上各有一条在途，一轮下来 fails 加的是在途条数而不是 1。放弃前仍要求最低采样数，避免一次 DNS 或网络抖动直接把整个 endpoint 清空。
// 返回 [等多久, 要不要放弃]
function Club_Endpoint_Backoff($reason, $age, $fails) {
    // 指向内网是确定性的，重试不会有不同结果。但内网判定依赖 DNS，本站解析被投毒或抽一次风就会误伤，所以隔两小时还是这个结论才算数
    if ($reason == 'blocked') return [3600, $age > 7200 && $fails >= 3];
    // 换 NS 的传播、域名续费后恢复、DNSSEC 配错修好，都是小时级的事，前两天密集探测才接得住；之后逐档拉开，一个月还没回来才认定是真没了
    if ($reason == 'unresolved') return [$age < 172800 ? 300 : ($age < 604800 ? 3600 : 21600), $age > 2592000 && $fails >= 7];
    // 对端临时挂掉是常态，这套阶梯本来就是照着它调的。顶档不敢拉太长：同一 endpoint 只放一条在途，这个间隔就等于探活间隔，拉长它不省并发、只让恢复更晚被发现
    return [$age < 300 ? 60 : ($age < 1800 ? 300 : ($age < 7200 ? 600 : 900)), $age > 604800 && $fails >= 7];
}

// 投递结果 -> 目标状态的全部决定。抽成纯函数是因为这几档的边界就是整套调度的不变量：什么时候清故障段、什么时候只推这一行、什么时候整条 endpoint 一起退、什么时候放弃。
// 入参是已经在行锁里读到的当前状态，出参是要写回去的目标状态，锁行、按 token 改写和日志留在 Club_Endpoint_Complete。
// $jitter 传 null 表示按退避档位随机抖动 —— 不抖的话一家恢复的那一秒几十个进程会一起扑上去，对端更容易限流，然后所有行齐步走向放弃
function Club_Endpoint_Decide($result, $fails, $since, $retry_at, $retries, $now, $jitter = null) {
    $fails = (int)$fails; $since = (int)$since; $retry_at = (int)$retry_at; $retries = (int)$retries;
    $plan = ['queue' => 'keep', 'due_at' => null, 'retries' => $retries, 'fails' => $fails, 'fail_since' => $since,
        'retry_at' => $retry_at, 'endpoint' => false, 'blacklist' => false, 'state' => '', 'age' => 0];
    switch ($result) {
        // 2xx，或者对端应用层给的终局拒绝。
        // 后者说明 DNS、TCP、TLS 到应用层全通，它只是永远不会收这一条，故障段照样该结束 —— 让一条谁都不收的活动去推整个 endpoint 的退避，就成了一行毒 payload 把好端端一家实例拉黑
        case 'ok': case 'rejected':
            $plan['queue'] = 'delete';
            if ($fails || $since || $retry_at) {
                $plan['fails'] = 0; $plan['fail_since'] = 0; $plan['retry_at'] = 0;
                $plan['age'] = $since ? $now - $since : 0; $plan['endpoint'] = true; $plan['state'] = 'recovered';
            } return $plan;
        // 本地就处理不掉的一行，一个字节都没出网。删掉它，但绝不能顺手清故障段：上面那两种清零的依据是「已经通到远端应用层」，这里什么都没证明，一条脏 queue 就能把一家真在挂的实例从退避里放出来
        case 'dropped': $plan['queue'] = 'delete'; return $plan;
        // 本站自己解析不动，什么都没证明：retries 和 fails 都不加，只把这行往后推。记在对端头上的话，本站 DNS 挂几天就能把关注的实例全拉黑一遍
        case 'local-dns': $plan['queue'] = 'defer'; $plan['due_at'] = $now + 300; $plan['state'] = 'deferred';
            // 同 target 的其他 queue 立刻重判也是同一个结论，整条 endpoint 一起推
            if ($retry_at < $now + 300) { $plan['retry_at'] = $now + 300; $plan['endpoint'] = true; }
            return $plan;
    }
    $begin = $since === 0; if ($begin) $since = $now;
    $plan['fails'] = ++$fails; $plan['fail_since'] = $since; $plan['age'] = $age = $now - $since;
    list($wait, $drop) = Club_Endpoint_Backoff($result, $age, $fails);
    $plan['retry_at'] = $now + $wait + (isset($jitter) ? (int)$jitter : mt_rand(0, (int)($wait / 4)));
    $plan['endpoint'] = true;
    // 单条 retries 每个故障段只加一次：连续宕机由 endpoint 的时长阶梯管，期间任何一条投成功都会清掉故障段，下一次失败才再算这条一笔
    if ($begin) $plan['retries'] = $retries + 1;
    // 判死刑只写黑名单和控制行。它名下可能有几千条 queue，一个事务里全删会把复制和锁等待一起拖下水，交给维护队列分批清
    if ($drop) { $plan['blacklist'] = true; $plan['state'] = 'blacklisted'; }
    // 对端在正常收别的行，就这一条一直过不去：签名它认不下来、或者只有这个 inbox 在 500。按行放弃，不牵连这家的其他投递
    elseif ($plan['retries'] >= 8) { $plan['queue'] = 'delete'; $plan['state'] = 'exhausted'; }
    // 行也要跟着推到解禁之后。只靠 retry_at 拦的话这几千行一直是「到期可领」，领到手才发现要退回来，白占一次租约
    else { $plan['queue'] = 'retry'; $plan['due_at'] = $plan['retry_at']; $plan['state'] = $begin ? 'failing' : 'still-failing'; }
    return $plan;
}

// 投递结果落库的唯一路径。先按 token 锁住控制行：租约过期且已被接管的旧 worker 回来时，它手上的 token 已经不是当前那个，这里一个字都写不进去，删不掉新 owner 的 queue。
// 已经发出去的 HTTP 收不回来，重复投递由远端按 Activity ID 去重
function Club_Endpoint_Complete($url, $token, $task, $result) {
    global $db, $curl; $now = time(); $plan = [];
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `fails`, `fail_since`, `retry_at` from `endpoints` where `url` = :url and `lease_token` = unhex(:token) for update');
        $pdo->execute([':url' => $url, ':token' => $token]); Club_Stat('scheduler_db_ops');
        if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) {
            $db->rollback(); Club_Stat('stale_tokens');
            Club_Log_Event('debug', 'stale lease result discarded', ['endpoint' => $url, 'token' => $token, 'result' => $result]);
            return false;
        }
        // 放弃分支要写 blacklist，而清理批次是先锁 blacklist 再删 queues。不在这里按同一顺序先取一次，两边就各持一半互相等：清理拿着 blacklist 等 queue 行，这边拿着 queue 行等 blacklist。
        // 行不存在时 RC 下不留 gap 锁，等于零成本
        $pdo = $db->prepare('select `target` from `blacklist` where `target` = :url for update');
        $pdo->execute([':url' => $url]); Club_Stat('scheduler_db_ops');
        // queue 也属于 completion 的授权边界。选中之后它可能被维护路径删除；只凭 worker 手上的旧数组继续记失败，会把另一个 endpoint 的当前状态一起推进。
        $pdo = $db->prepare('select `tid`, `retries` from `queues` where `id` = :id and `target` = :url for update');
        $pdo->execute([':id' => $task['id'], ':url' => $url]); Club_Stat('scheduler_db_ops');
        if (!($queue = $pdo->fetch(PDO::FETCH_ASSOC))) {
            Club_Endpoint_Reschedule($url, $token, (int)$row['retry_at']);
            $db->commit(); Club_Stat('stale_queues');
            Club_Log_Event('debug', 'queue result discarded, row is no longer owned by endpoint', ['endpoint' => $url, 'token' => $token, 'queue' => $task['id'], 'result' => $result]);
            return false;
        }
        $task['tid'] = $queue['tid'];
        $plan = Club_Endpoint_Decide($result, $row['fails'], $row['fail_since'], $row['retry_at'], $queue['retries'], $now);
        $task['retries'] = $plan['retries'];
        if ($plan['endpoint']) {
            $pdo = $db->prepare('update `endpoints` set `fails` = :fails, `fail_since` = :since, `retry_at` = :retry_at where `url` = :url and `lease_token` = unhex(:token)');
            $pdo->execute([':url' => $url, ':token' => $token, ':fails' => $plan['fails'], ':since' => $plan['fail_since'], ':retry_at' => $plan['retry_at']]);
        }
        if ($plan['queue'] === 'delete') Club_Queue_Delete($task['id'], $task['tid']);
        elseif ($plan['queue'] === 'defer') Club_Queue_Defer($task['id'], $plan['due_at']);
        elseif ($plan['queue'] === 'retry') Club_Queue_Retry($task['id'], $plan['retries'], $plan['due_at']);
        if ($plan['blacklist']) Club_Blacklist_Add($url, $now);
        // queue 动完之后才重算：它照 min(due_at) 取值，删掉或推后的那一行必须已经落在这个事务里
        Club_Endpoint_Reschedule($url, $token, $plan['retry_at']);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Stat('endpoint_done');
    $state = $plan['state'];
    // endpoint 这一层只记状态变化：开始挂、恢复、被放弃。中间那些重复失败，一家大实例挂一天就是几万行，全记等于把 event 日志冲掉
    if ($state == 'recovered') Club_Log_Event('info', 'endpoint recovered: '.$url, ['fails' => $plan['fails'], 'age' => $plan['age'], 'via' => $result == 'ok' ? 'delivery' : 'transport']);
    elseif ($state == 'failing') Club_Log_Event('info', 'endpoint started failing: '.$url, ['reason' => $result, 'wait' => $plan['retry_at'] - $now]);
    elseif ($state == 'blacklisted') Club_Log_Event('info', 'endpoint blacklisted: '.$url, ['reason' => $result, 'fails' => $plan['fails'], 'age' => $plan['age']]);
    // 这一条 payload 自己的去向，跟 endpoint 是两回事
    if ($result == 'ok') Club_Log_Event('debug', 'push delivered', ['club' => $task['club'], 'target' => $url, 'retries' => (int)$task['retries']]);
    elseif ($result == 'rejected') Club_Log_Event('info', 'push dropped, target refused the activity', ['club' => $task['club'], 'target' => $url, 'code' => isset($curl) ? $curl->httpStatusCode : 0]);
    elseif ($result == 'local-dns') Club_Log_Event('debug', 'push deferred, waiting for local dns', ['club' => $task['club'], 'target' => $url, 'retry' => 300]);
    elseif ($result == 'dropped') Club_Log_Event('debug', 'queue dropped without contacting the target', ['club' => $task['club'], 'target' => $url]);
    elseif ($state == 'exhausted') Club_Log_Event('warning', 'push dropped after '.$task['retries'].' failed attempts', ['club' => $task['club'], 'target' => $url, 'reason' => $result]);
    elseif ($state == 'blacklisted') Club_Log_Event('debug', 'push held, endpoint was blacklisted', ['club' => $task['club'], 'target' => $url, 'reason' => $result]);
    else Club_Log_Event('debug', 'push failed, will retry', ['club' => $task['club'], 'target' => $url, 'reason' => $result, 'retries' => (int)$task['retries'], 'retry' => $plan['retry_at'] - $now]);
    return true;
}

// 放弃一个 endpoint。探活时间摊到一天里，避免一家大实例的目标同时占满 worker
function Club_Blacklist_Add($url, $now) {
    global $db;
    $check = $now + mt_rand(0, 86400);
    $pdo = $db->prepare('insert ignore into `blacklist`(`target`,`created_at`,`check_at`,`checks`) values (:target, :now, :check, 0)');
    $pdo->execute([':target' => $url, ':now' => $now, ':check' => $check]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

// 对端有活动发得进来，说明它至少已经能出网，这是黑名单目标复活最早的旁证，比摊到一天里的探活周期早得多。
// 但这只是旁证：能出网不等于它自己的 inbox 收得下，判活仍旧由探活那一次出网说了算。
// 所以这里只动 check_at 这个调度提示，checks 不碰 —— 那是这个目标的失败历史，跟 endpoints.fails 一样不该被一次旁证抹掉。
// 提前到一小时上下而不是立刻，是因为对端一直在给我们发东西、自己的 inbox 却真的坏着时，这条路会被它的入站流量反复触发：这一小时是那种情况下的探活间隔下限，同时把正常恢复的等待从一天压到一小时。
// 那 300 秒抖动是给一家实例的多条 target 用的，别让它们同一秒被领走，所以实际落在 60 到 65 分钟之间
function Club_Blacklist_Sooner($actor) {
    global $db; $now = time(); $check = $now + 3600 + mt_rand(0, 300);
    // 判定用固定的窗口上界，写进去的才是带抖动的那个值。拿 :check 自己当判据的话，同一批入站活动里抽到更小随机数的那条又会满足条件，一轮恢复要写好几次库、记好几行日志
    // 两个 inbox 用 union 摊成派生表再按 target 等值连接：target 是 blacklist 的主键，这样每行仍是一次主键取锁，取锁顺序跟 Claim、Result 一致。
    // 写成 `b`.`target` in (`u`.`inbox`, `u`.`shared_inbox`) 用不上主键，每条入站活动都会全表扫一遍 blacklist。两处 actor 各给一个参数名，是为了不依赖 PDO 的语句模拟去复用同名占位符。
    // 已经排得更早的不动：一家实例回来时入站活动是成批到的，只有第一条该写库。租约里的那行也不动，它正在被探活；restore_pending_at 非空的已经确认活了，正等维护队列清 backlog，催它没有意义
    $pdo = $db->prepare('update `blacklist` `b` join (select `inbox` as `target` from `users` where `actor` = :actor union select `shared_inbox` from `users` where `actor` = :owner)'.
        ' `u` on `b`.`target` = `u`.`target` set `b`.`check_at` = :check where `b`.`restore_pending_at` is null and `b`.`check_at` > :cutoff and `b`.`lease_until` <= :now');
    $pdo->execute([':actor' => $actor, ':owner' => $actor, ':check' => $check, ':cutoff' => $now + 3900, ':now' => $now]);
    Club_Stat('scheduler_db_ops');
    if (!$pdo->rowCount()) return false;
    Club_Log_Event('info', 'blacklist probe pulled in by inbound activity', ['actor' => $actor, 'rows' => $pdo->rowCount(), 'wait' => $check - $now]);
    return true;
}

// 领一条待探活的黑名单行。restore_pending_at 非空的不领：那些已经确认活过来了，正在等维护队列把历史 queue 清完，再探一次只是白白多一次出网
function Club_Blacklist_Claim($now, $lease = 120) {
    global $db;
    // 跟 endpoint 领取同一套取锁顺序：候选只读不锁，领取按主键 CAS。沿 schedule 扫描会跟 Result、Cleanup 那些先按主键锁行的路径咬成 1213，理由见 Club_Lease_Pick
    $pdo = $db->prepare('select `target` from `blacklist` where `restore_pending_at` is null and `check_at` <= :now and `lease_until` <= :now order by `check_at` limit 32');
    $pdo->execute([':now' => $now]);
    $candidates = $pdo->fetchAll(PDO::FETCH_COLUMN, 0);
    Club_Stat('scheduler_db_ops');
    if (!$candidates) return null;
    $token = Club_Token();
    $claim = $db->prepare('update `blacklist` set `lease_token` = unhex(:token), `lease_until` = :until'.
        ' where `target` = :target and `restore_pending_at` is null and `check_at` <= :now and `lease_until` <= :now');
    $target = Club_Lease_Pick($candidates, $token, function ($target) use ($claim, $token, $now, $lease) {
        $claim->execute([':target' => $target, ':token' => $token, ':until' => $now + $lease, ':now' => $now]);
        Club_Stat('scheduler_db_ops');
        return (bool)$claim->rowCount();
    });
    if (!isset($target)) {
        Club_Log_Event('debug', 'blacklist claim lost every candidate it tried', ['candidates' => count($candidates)]);
        return null;
    }
    $pdo = $db->prepare('select `target`, `checks`, `check_at` from `blacklist` where `lease_token` = unhex(:token)');
    $pdo->execute([':token' => $token]); Club_Stat('scheduler_db_ops');
    if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) {
        Club_Log_Event('warning', 'blacklist claim lost before read', ['token' => $token]);
        return null;
    }
    $row['token'] = $token; Club_Stat('probe_claims');
    Club_Log_Event('debug', 'blacklist probe claimed', ['target' => $row['target'], 'token' => $token, 'checks' => (int)$row['checks']]);
    return $row;
}

// 探活出网前的最后一道闸，跟投递用同一套：租约到期后仍以 token CAS 判定所有权，未被接管可原子续租，已被接管则 token 不同。顺带确认这一行还没被别人恢复掉。
function Club_Blacklist_Authorize($target, $token, $next, $lease = 120) {
    global $db; $now = time();
    $pdo = $db->prepare('update `blacklist` set `lease_token` = unhex(:next), `lease_until` = :until where `target` = :target and `lease_token` = unhex(:token) and `restore_pending_at` is null');
    $pdo->execute([':target' => $target, ':token' => $token, ':next' => $next, ':until' => $now + $lease]);
    Club_Stat('scheduler_db_ops');
    if ($pdo->rowCount()) return true;
    Club_Stat('renew_failed');
    Club_Log_Event('debug', 'pre-HTTP token renewal rejected', ['target' => $target, 'token' => $token, 'probe' => true]);
    return false;
}

// resolver 没给出结果就退回来。这一轮既没问过 DNS 也没问过对端，checks 不能加，check_at 也只短推一会儿：本站 DNS 抖一下不该把恢复推迟一整天
function Club_Blacklist_Defer($target, $token, $reason) {
    global $db; $check = time() + 300 + mt_rand(0, 75);
    $pdo = $db->prepare('update `blacklist` set `check_at` = :check, `lease_token` = null, `lease_until` = 0 where `target` = :target and `lease_token` = unhex(:token)');
    $pdo->execute([':target' => $target, ':token' => $token, ':check' => $check]);
    Club_Stat('scheduler_db_ops');
    if (!$pdo->rowCount()) Club_Stat('stale_tokens');
    Club_Log_Event('debug', $pdo->rowCount() ? 'blacklist probe deferred' : 'blacklist probe defer skipped, lease is no longer ours',
        ['target' => $target, 'token' => $token, 'reason' => $reason, 'retry' => $check - time()]);
    return (bool)$pdo->rowCount();
}

// 探活结果 -> 目标状态。$queued 是它名下还有没有历史 backlog，$jitter 同 Club_Endpoint_Decide
function Club_Blacklist_Decide($alive, $checks, $queued, $now, $jitter = null) {
    // 活过来了但历史 backlog 还在。先只记状态：blacklist 行留着继续挡住入队和出网，等维护队列分批清干净再真正解禁，不然几千条陈年活动会一起复活
    if ($alive) return ['state' => $queued ? 'pending' : 'restored', 'checks' => (int)$checks, 'check_at' => null];
    // 敲了一个月还是没人应的实例不必天天再敲，间隔跟着 checks 拉开，一周封顶。拉长不会推迟恢复：对端只要有一条活动发得进来，Club_Blacklist_Sooner 就把 check_at 拉回一小时内，
    // 而一条活动都发不进来的实例，本来就只能靠这个周期慢慢试
    $checks = (int)$checks + 1; $wait = 86400 * min($checks, 7);
    return ['state' => 'dead', 'checks' => $checks, 'check_at' => $now + $wait + (isset($jitter) ? (int)$jitter : mt_rand(0, (int)($wait / 4)))];
}

// 探活结果落库。跨表短事务统一按 endpoint -> blacklist -> queues 的顺序取锁
function Club_Blacklist_Result($target, $token, $alive) {
    global $db; $now = time(); $state = ''; $checks = 0;
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `url` from `endpoints` where `url` = :target for update');
        $pdo->execute([':target' => $target]);
        $endpoint = (bool)$pdo->fetch(PDO::FETCH_COLUMN, 0);
        $pdo = $db->prepare('select `checks` from `blacklist` where `target` = :target and `lease_token` = unhex(:token) for update');
        $pdo->execute([':target' => $target, ':token' => $token]);
        if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) {
            $db->rollback(); Club_Stat('stale_tokens');
            Club_Log_Event('debug', 'stale probe result discarded', ['target' => $target, 'token' => $token]);
            return '';
        }
        $queued = false;
        if ($alive) {
            $pdo = $db->prepare('select 1 from `queues` where `target` = :target limit 1');
            $pdo->execute([':target' => $target]);
            $queued = (bool)$pdo->fetch(PDO::FETCH_COLUMN, 0);
        }
        $plan = Club_Blacklist_Decide($alive, $row['checks'], $queued, $now);
        $checks = $plan['checks']; $state = $plan['state'];
        if ($state === 'dead') {
            $pdo = $db->prepare('update `blacklist` set `checks` = :checks, `check_at` = :check, `lease_token` = null, `lease_until` = 0 where `target` = :target and `lease_token` = unhex(:token)');
            $pdo->execute([':target' => $target, ':token' => $token, ':checks' => $checks, ':check' => $plan['check_at']]);
        } elseif ($state === 'pending') {
            $pdo = $db->prepare('update `blacklist` set `restore_pending_at` = :now, `lease_token` = null, `lease_until` = 0 where `target` = :target and `lease_token` = unhex(:token)');
            $pdo->execute([':target' => $target, ':token' => $token, ':now' => $now]);
        } else {
            $pdo = $db->prepare('delete from `endpoints` where `url` = :target');
            $pdo->execute([':target' => $target]);
            $pdo = $db->prepare('delete from `blacklist` where `target` = :target and `lease_token` = unhex(:token)');
            $pdo->execute([':target' => $target, ':token' => $token]);
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Stat('scheduler_db_ops', 4);
    // 恢复投递跟停止投递一样是个大事件，两头都留一行才对得上
    if ($state == 'restored') Club_Log_Event('info', 'endpoint restored from blacklist: '.$target, ['checks' => $checks, 'endpoint' => $endpoint]);
    elseif ($state == 'pending') Club_Log_Event('info', 'recovery confirmed, cleanup pending: '.$target, ['checks' => $checks]);
    else Club_Log_Event('debug', 'blacklist probe found target still down', ['target' => $target, 'checks' => $checks]);
    return $state;
}

// 分批清掉黑名单目标的历史 queues，backlog 见底之后连它的控制行一起删掉。
// 每批都是独立的有界事务，而且必须先锁住 blacklist 行确认它还在：只凭事务外读到的旧 target 删，会在它刚恢复的那一刻把新入队的活动一起清掉
function Club_Blacklist_Cleanup($limit = 500) {
    global $db; $now = time(); $rows = 0; $state = '';
    // 三个条件各接一次收尾：已确认恢复的排在前面，而且哪怕它的 queues 早就清光了也要选中，最后一步删 blacklist 行只在这条路径上，漏掉它这个 target 就永远解禁不了；
    // 控制行还在的同样要选中，否则 queue 数正好是批大小的整数倍时，删空的那一批之后这个 target 就再也匹配不上，控制行会永远留着。
    // 有 backlog 的排在只剩控制行的前面：一次调用只处理一个 target，而删控制行那一批删掉 0 条 queue，拿不到 worker 「删满一批就立刻再来」的重排，只能等满一个 cleanup 间隔。
    // 单按 created_at 排的话，积压的几百条陈年收尾会一条一小格地把新拉黑目标的 backlog 连同它的 task 顶到几小时之后
    $pdo = $db->query('select `target`, `restore_pending_at` from `blacklist` where `restore_pending_at` is not null'.
        ' or exists (select 1 from `queues` where `queues`.`target` = `blacklist`.`target`) or exists (select 1 from `endpoints` where `endpoints`.`url` = `blacklist`.`target`)'.
        ' order by `restore_pending_at` is null, exists (select 1 from `queues` where `queues`.`target` = `blacklist`.`target`) desc, `created_at` limit 1');
    Club_Stat('scheduler_db_ops');
    if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) return 0;
    $target = $row['target'];
    try {
        $db->beginTransaction();
        // 取锁顺序跟 Complete、Result、Restore 一致：endpoint -> blacklist -> queues。控制行早就删掉的目标在这里不留 gap 锁，等于零成本
        $pdo = $db->prepare('select `url` from `endpoints` where `url` = :target for update');
        $pdo->execute([':target' => $target]);
        $pdo = $db->prepare('select `restore_pending_at` from `blacklist` where `target` = :target for update');
        $pdo->execute([':target' => $target]);
        if (($row = $pdo->fetch(PDO::FETCH_ASSOC)) !== false) {
            $pdo = $db->prepare('delete from `queues` where `target` = :target limit '.(int)$limit);
            $pdo->execute([':target' => $target]);
            $rows = $pdo->rowCount();
            // backlog 见底、又不是在等解禁：这个 target 以后不会再有活动入队，控制行从此没有读者，删掉它 endpoints 就只剩活跃对端。
            // 不判租约：拉黑之后 next_at 为空、领不到新租约，而拖到这一刻才回来的旧 owner 在 Complete 里查不到行，走的正是「陈旧结果丢弃」那条出口
            if ($rows < $limit && !isset($row['restore_pending_at'])) {
                $pdo = $db->prepare('delete from `endpoints` where `url` = :target');
                $pdo->execute([':target' => $target]);
                if ($pdo->rowCount()) $state = 'detached';
            }
        } else $state = 'gone';
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Monitor_Count('cleanup_rows', $rows);
    Club_Log_Event('debug', 'blacklist cleanup batch', ['target' => $target, 'rows' => $rows, 'state' => $state ?: 'ok']);
    // 这一批没删满，说明 backlog 已经见底，可以试着收尾了
    if ($state === '' && $rows < $limit && isset($row['restore_pending_at'])) Club_Blacklist_Restore($target, $now);
    return $rows;
}

// 真正解禁：blacklist 行在这一步提交之前一直挡着入队和出网，提交之后只接受未来的新活动，过去的 backlog 不会被随机复活
function Club_Blacklist_Restore($target, $now) {
    global $db; $restored = false;
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `url` from `endpoints` where `url` = :target for update');
        $pdo->execute([':target' => $target]);
        $pdo = $db->prepare('select `restore_pending_at` from `blacklist` where `target` = :target for update');
        $pdo->execute([':target' => $target]);
        $row = $pdo->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && isset($row['restore_pending_at'])) {
            $pdo = $db->prepare('select 1 from `queues` where `target` = :target limit 1');
            $pdo->execute([':target' => $target]);
            if (!$pdo->fetch(PDO::FETCH_COLUMN, 0)) {
                $pdo = $db->prepare('delete from `endpoints` where `url` = :target');
                $pdo->execute([':target' => $target]);
                $pdo = $db->prepare('delete from `blacklist` where `target` = :target');
                $pdo->execute([':target' => $target]);
                $restored = true;
            }
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Stat('scheduler_db_ops', 5);
    if ($restored) Club_Log_Event('info', 'endpoint restored from blacklist: '.$target, ['pending' => $now - (int)$row['restore_pending_at']]);
    return $restored;
}

// 以 queues 为投递真相、blacklist 为 disabled 真相，分页把 endpoints 补齐、修正。不做整表 group by：queues 上百万行时那一条语句就够把唯一的维护 slot 卡到超时。
// 不扫 blacklist：拉黑目标的控制行在 backlog 清完时就由 Club_Blacklist_Cleanup 删掉了，缺行是它的终态而不是待修的破损，补出来只会跟清理来回拉锯。
// 两段各自留一个稳定游标，每次只走一页，中途被打断下次接着走
function Club_Reconcile_Step($limit = 200) {
    global $db; static $phase = 0, $cursor = '';
    $now = time(); $seen = 0; $repairs = 0; $pruned = 0;
    if ($phase === 0) {
        // 等着被清理的黑名单 queue 不能算数，否则刚拉黑的 endpoint 会被重新排上
        $pdo = $db->prepare('select `q`.`target` from `queues` `q` left join `blacklist` `b` on `q`.`target` = `b`.`target`'.
            ' where `b`.`target` is null and `q`.`target` > :cursor group by `q`.`target` order by `q`.`target` limit '.(int)$limit);
        $pdo->execute([':cursor' => $cursor]);
        foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $target) {
            $cursor = $target; $seen++;
            if (Club_Reconcile_Endpoint($target, $now)) $repairs++;
        }
    } else {
        // next_at 本身可能损坏成非空，不能只扫 NULL；锁行后以 queue/blacklist 重判。
        $pdo = $db->prepare('select `url` from `endpoints` where `url` > :cursor and `lease_until` <= :now order by `url` limit '.(int)$limit);
        $pdo->execute([':cursor' => $cursor, ':now' => $now]);
        foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $url) {
            $cursor = $url; $seen++;
            // 起算点缺失的行只是被扶正，不算回收
            if (($took = Club_Endpoint_Prune($url, $now)) === 'pruned') $pruned++;
            elseif ($took) $repairs++;
        }
    }
    Club_Stat('scheduler_db_ops');
    // 回收跟修复分开计：修复是「不变量被破坏过」，回收是「一条 endpoint 真的离开了」，合在一个数里的话，稳态下的回收流量会把偶发的修复彻底盖住
    Club_Monitor_Count('reconciliation_repairs', $repairs);
    Club_Monitor_Count('endpoints_pruned', $pruned);
    // 这一页没走满就是走到头了，换下一段重新开始
    if ($seen < $limit) { $phase = ($phase + 1) % 2; $cursor = ''; }
    return $seen;
}

// 这一行的调度状态跟 queues/blacklist 算出来的目标对不对得上。无锁预筛和锁内重判是同一条判据，各写一份的话两边会慢慢漂开，而漂出来的行恰好是「预筛说不用修、锁内说要修」那种，永远修不掉。
// idle_since 和 next_at 必须同进同出：只比 next_at 的话，一行两者不一致的记录没有任何路径会去修 —— 回收只认 idle_since，而它错了这行就永远等不到宽限期
function Club_Endpoint_Drifted($next, $row) {
    return $next !== (isset($row['next_at']) ? (int)$row['next_at'] : null) || ((int)$row['idle_since'] > 0) !== !isset($next);
}

// 一条空置控制行此刻该怎么处置，同样是预筛和锁内共用的判据。'prune' 删掉、'idle' 就地补上起算点、false 留着不动。
// 不判 fails：它只在投成功时清零，而一条没有 queue 的 endpoint 永远等不到下一次投递，要求 fails 为零等于让带过故障的空行永久留下
function Club_Endpoint_Prune_Decide($lease, $idle, $retry, $queued, $blocked, $now, $before) {
    if (!isset($lease) || (int)$lease > $now || (int)$queued || (int)$blocked) return false;
    // 起算点缺失的无主行就地转成空闲态而不是删掉：它们通常由领取路径自愈（领到手发现没有 queue 就重排），但 next_at 若损坏成未来时间就永远领不到，而这一段是唯一还会扫到它的地方
    if (!(int)$idle) return 'idle';
    return (int)$idle <= $before && (int)$retry <= $now ? 'prune' : false;
}

// 单条 endpoint 的修复。缺行先按健康默认值补出来，再在短事务里锁行重算 next_at 和 idle_since。
// 只写这两列 —— fails/fail_since/retry_at 是 endpoint 自己的故障历史，queues 里恢复不出来，被这里覆盖掉就等于把一家挂了一个月的实例重新当成健康的
function Club_Reconcile_Endpoint($url, $now) {
    global $db; $repaired = false; $next = null;
    // 先无锁看一眼。直接 for update 的话，每一轮对账都要在每一条 endpoint 上排到正在投递的那个完成事务后面去等锁，等到了却发现租约还在、什么都不用做；
    // 稳态下绝大多数行本来就是对的，脏读只用来跳过，进了事务照样从头重判
    $pdo = $db->prepare('select `next_at`, `retry_at`, `idle_since`, `lease_until` from `endpoints` where `url` = :url');
    $pdo->execute([':url' => $url]); Club_Stat('scheduler_db_ops');
    if (($peek = $pdo->fetch(PDO::FETCH_ASSOC)) !== false) {
        if ((int)$peek['lease_until'] > $now) return false;
        if (!Club_Endpoint_Drifted(Club_Endpoint_Desired($url, (int)$peek['retry_at']), $peek)) return false;
    }
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `next_at`, `retry_at`, `idle_since`, `lease_until` from `endpoints` where `url` = :url for update');
        $pdo->execute([':url' => $url]);
        // 缺行才补，而且补在事务里：并发入队已经建好这一行时 insert ignore 不写，重新 select 会等它提交后拿到它的版本，不会把它的故障状态盖掉
        if (($row = $pdo->fetch(PDO::FETCH_ASSOC)) === false) {
            $pdo = $db->prepare('insert ignore into `endpoints`(`url`, `next_at`, `idle_since`) values (:url, null, :now)');
            $pdo->execute([':url' => $url, ':now' => $now]);
            // 健康历史是 endpoint 自己的状态，queues 里恢复不出来，补出来的是有损的
            if ($pdo->rowCount()) Club_Log_Event('warning', 'endpoint control row rebuilt with healthy defaults', ['endpoint' => $url]);
            $pdo = $db->prepare('select `next_at`, `retry_at`, `idle_since`, `lease_until` from `endpoints` where `url` = :url for update');
            $pdo->execute([':url' => $url]);
            $row = $pdo->fetch(PDO::FETCH_ASSOC);
        }
        // 租约还在有效期内：这一行归当前 owner，它完成时自己会重算
        if ($row !== false && (int)$row['lease_until'] <= $now) {
            $next = Club_Endpoint_Desired($url, (int)$row['retry_at']);
            if (Club_Endpoint_Drifted($next, $row)) {
                $pdo = $db->prepare('update `endpoints` set `next_at` = :next,'.
                    ' `idle_since` = if(:next is null, if(`idle_since` > 0, `idle_since`, :now), 0) where `url` = :url and `lease_until` <= :now');
                $pdo->execute([':url' => $url, ':next' => $next, ':now' => $now]);
                $repaired = (bool)$pdo->rowCount();
            }
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Stat('scheduler_db_ops', 2);
    if ($repaired) Club_Log_Event('warning', 'endpoint schedule repaired', ['endpoint' => $url, 'next_at' => $next]);
    return $repaired;
}

// 回收空置够久的控制行。事务外确认过的条件到这里可能已经不成立，锁上之后要全部重判一遍。
// 空了就删是错的：投递完成只把 next_at 置空，一个几千人的群组每投一轮就让名下几千行同时变空，几分钟后的下一轮又原样建回来。
// 那样换不回空间（页只回到 free list，文件不缩），却要拿维护槽把同一批主键删一遍建一遍，还会顺手丢掉 fails/fail_since。
// 隔一周再看，剩下的才是真的不会再来的 inbox：群组的投稿间隔本来就可能按周算，宽限期比它短的话，同一批行每个投稿周期都要重来一遍，等于没有宽限期。
// 不判 fails：它只在投成功时清零，而一条没有 queue 的 endpoint 永远等不到下一次投递，要求 fails 为零等于让带过故障的空行永久留下。
// idle_since 还没起算的无主行在这里就地转成空闲态而不是删掉：它们通常由领取路径自愈（领到手发现没有 queue 就重排），但 next_at 若损坏成未来时间就永远领不到，而这一段是唯一还会扫到它的地方。
// 返回 'pruned' 或 'idled' 区分这两件事
function Club_Endpoint_Prune($url, $now, $idle = 604800) {
    global $db; $pruned = false; $idled = false; $before = $now - $idle;
    // 跟对账同一个道理：能删的是少数，不先筛一遍就是每一行都开一次写锁事务，把维护 slot 一条条押在投递的完成事务后面
    $pdo = $db->prepare('select `e`.`lease_until` as `lease`, `e`.`idle_since` as `idle`, `e`.`retry_at` as `retry`, (select count(*) from `queues`'.
        ' where `target` = :url) as `queued`, (select count(*) from `blacklist` where `target` = :url) as `blocked` from `endpoints` as `e` where `e`.`url` = :url');
    $pdo->execute([':url' => $url]); Club_Stat('scheduler_db_ops');
    $peek = $pdo->fetch(PDO::FETCH_ASSOC) ?: [];
    // 稳态下没有 queue 的行必然已经起算过，所以 'idle' 那一类进事务不会加成本
    if (!Club_Endpoint_Prune_Decide($peek['lease'] ?? null, $peek['idle'] ?? 0, $peek['retry'] ?? 0, $peek['queued'] ?? 0, $peek['blocked'] ?? 0, $now, $before)) return false;
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `idle_since`, `retry_at`, `lease_until` from `endpoints` where `url` = :url for update');
        $pdo->execute([':url' => $url]);
        $row = $pdo->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $pdo = $db->prepare('select (select count(*) from `queues` where `target` = :url) as `queued`, (select count(*) from `blacklist` where `target` = :url) as `blocked`');
            $pdo->execute([':url' => $url]);
            $keep = $pdo->fetch(PDO::FETCH_ASSOC);
            $take = Club_Endpoint_Prune_Decide($row['lease_until'], $row['idle_since'], $row['retry_at'], $keep['queued'], $keep['blocked'], $now, $before);
            if ($take === 'idle') {
                $pdo = $db->prepare('update `endpoints` set `next_at` = null, `idle_since` = :now where `url` = :url and `lease_until` <= :now and `idle_since` = 0');
                $pdo->execute([':url' => $url, ':now' => $now]);
                $idled = (bool)$pdo->rowCount();
            } elseif ($take === 'prune') {
                $pdo = $db->prepare('delete from `endpoints` where `url` = :url and `lease_until` <= :now and `retry_at` <= :now and `idle_since` > 0 and `idle_since` <= :before');
                $pdo->execute([':url' => $url, ':now' => $now, ':before' => $before]);
                $pruned = (bool)$pdo->rowCount();
            }
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Stat('scheduler_db_ops', 3);
    // 一条 = 一个 inbox 真的从这个站消失了，频率就是关注者的流失速率
    if ($pruned) Club_Log_Event('debug', 'idle endpoint removed', ['endpoint' => $url, 'idle' => $now - (int)$row['idle_since']]);
    // 起算点缺失说明有路径没把 next_at 和 idle_since 一起写，不是常态
    elseif ($idled) Club_Log_Event('warning', 'endpoint idle clock repaired', ['endpoint' => $url]);
    return $pruned ? 'pruned' : ($idled ? 'idled' : false);
}

// 只有维护队列统计的那几个区间计数。跟 Club_Stat 分开：那一组每 60 秒被汇总带走，而这些要凑满 5 分钟才输出一次
function Club_Monitor_Count($key, $value = 1) {
    static $data = [];
    if (!isset($key)) { $out = $data; $data = []; return $out; }
    if (!isset($data[$key])) $data[$key] = 0;
    $data[$key] += $value;
    return $data[$key];
}

// 全站视角的周期记录，只有维护队列跑。snapshot 是此刻的数据库存量，window 是两次快照之间发生的事，两类混在一起就没法算速率了
function Club_Monitor_Snapshot($reset = false) {
    global $db; static $last = 0;
    if ($reset) { $last = 0; return false; }
    $now = time();
    // 第一次只立窗口起点；执行周期由 worker_maintain 统一负责，数据库失败时外层会在 5 秒后重试，成功前不推进起点
    if (!$last) { $last = $now; return false; }
    $window = $now - $last;
    // total 含着待回收的空行，单看它读不出「有多少 endpoint 在排队投递」。idle 本身就是等着被回收的那部分，卡住了它只会单调涨。
    // 不必拿它减 blacklist：拉黑目标的控制行在 backlog 清完那一批就删了，落在 idle 里的只剩正在清的那几条，同一行的 blacklisted_queues 非零就是它们还在的信号。
    // due/leased/oldest 都带 next_at 非空或租约条件，空行本来就进不去
    $pdo = $db->prepare('select count(*) as `total`, sum(`next_at` is not null and `next_at` <= :now and `retry_at` <= :now and `lease_until` <= :now) as `due`,'.
        ' sum(`lease_until` > :now) as `leased`, sum(`idle_since` > 0) as `idle`, min(if(`next_at` is not null and `lease_until` <= :now, `next_at`, null)) as `oldest` from `endpoints`');
    $pdo->execute([':now' => $now]);
    $endpoints = $pdo->fetch(PDO::FETCH_ASSOC) ?: [];
    $pdo = $db->prepare('select count(*) as `total`, sum(`restore_pending_at` is null and `check_at` <= :now) as `due`, sum(`restore_pending_at` is null and `check_at` <= :overdue) as `overdue`,'.
        ' sum(`restore_pending_at` is not null) as `pending`, min(if(`restore_pending_at` is null, `check_at`, null)) as `oldest` from `blacklist`');
    $pdo->execute([':now' => $now, ':overdue' => $now - 3600]);
    $blacklist = $pdo->fetch(PDO::FETCH_ASSOC) ?: [];
    $pdo = $db->query('select count(*) from `queues` `q` join `blacklist` `b` on `q`.`target` = `b`.`target`');
    $backlog = (int)$pdo->fetch(PDO::FETCH_COLUMN, 0);
    $pdo = $db->prepare('select count(*) as `total`, sum(length(`ips`) > 0) as `positive`, sum(`lock_until` > :now) as `locked` from `dns`');
    $pdo->execute([':now' => $now]);
    $dns = $pdo->fetch(PDO::FETCH_ASSOC) ?: [];
    Club_Stat('scheduler_db_ops', 4);
    Club_Log_Event('info', 'maintenance snapshot', ['window_s' => $window, 'endpoints' => (int)$endpoints['total'],
        'endpoints_due' => (int)$endpoints['due'], 'endpoints_leased' => (int)$endpoints['leased'], 'endpoints_idle' => (int)$endpoints['idle'],
        'endpoint_schedule_lag_s' => isset($endpoints['oldest']) ? max(0, $now - (int)$endpoints['oldest']) : 0, 'blacklist' => (int)$blacklist['total'],
        'blacklist_due' => (int)$blacklist['due'], 'blacklist_overdue' => (int)$blacklist['overdue'], 'blacklist_pending' => (int)$blacklist['pending'],
        'blacklist_check_lag_s' => isset($blacklist['oldest']) ? max(0, $now - (int)$blacklist['oldest']) : 0, 'blacklisted_queues' => $backlog,
        'dns_rows' => (int)$dns['total'], 'dns_positive' => (int)$dns['positive'], 'dns_locked' => (int)$dns['locked']] + Club_Monitor_Count(null), 'stat');
    // 查询完整跑完再推进节流点；中途的 PDOException 会让外层重试立即补上这一轮。
    $last = $now;
    return true;
}

function Club_NameTag_Render($club, $str, $tag) {
    global $config;
    $str = str_replace(array_keys($tag), array_values($tag), $str);
    return str_replace([':club_name:', ':local_domain:'], [$club, $config['base']], $str);
}

// 远端内容一律转义后输出。ENT_SUBSTITUTE 保证非法 UTF-8 只替换坏字节，不加则整个字段变空
function Club_Html($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// 只放行 http(s)，否则 href 里能塞 javascript:
function Club_Html_Url($url) {
    return preg_match('#^https?://#i', (string)$url) ? Club_Html($url) : '';
}

function Club_Template($name, $vars = []) {
    return require(APP_ROOT.'/src/template/'.$name.'.php');
}

function Club_Json_Encode($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function Club_Json_Output($data, $format = 0, $status = 200) {
    switch ($format) {
        case 1: $format = 'jrd+json'; break;
        case 2: $format = 'activity+json'; break;
        default: $format = 'json'; break;
    } header('Content-type: application/'.$format.'; charset=utf-8');

    if ($status != 200) {
        http_response_code($status);
        $data = array_merge(['code' => $status], $data);
    } echo Club_Json_Encode($data);
}

function to_array($data) {
    return is_array($data) ? $data : [$data];
}
