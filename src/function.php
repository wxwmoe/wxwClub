<?php require_once(__DIR__.'/class/curl.php');

// 跳转自己跟：交给 curl 的话每一跳既过不了内网检查，签名也对不上新 host。跳数与 Mastodon 一致
function ActivityPub_GET($url, $club, $hops = 3) {
    global $curl;
    for ($i = 0; $i <= $hops; $i++) {
        // 拦下的是内网地址和非 http(s)，属于有人在拿本站当探针，不能只是静默返回
        if (($public = Club_Url_Public($url)) === false) {
            Club_Log_Event('warning', 'fetch blocked, url is not public', ['url' => $url, 'hop' => $i]);
            return false;
        }
        // 解析不出来只是这次拉不到，跟被人当探针不是一回事，别混进 warning 里
        if ($public === null) {
            Club_Log_Event(Club_Url_Resolve_Healthy() ? 'info' : 'warning',
                'fetch skipped, cannot resolve host', ['url' => $url, 'hop' => $i]);
            return false;
        }
        $date = gmdate('D, d M Y H:i:s T');
        // 把验过的 IP 一起交下去，别让 curl 自己再解析一遍
        $result = ActivityPub_CURL($url, $date, [
            'Signature' => ActivityPub_Signature($url, $club, $date)
        ], null, $public);
        if ($result === false || !in_array($curl->httpStatusCode, [301, 302, 303, 307, 308])) return $result;
        if (!($location = Club_Header_Get($curl->responseHeaders, 'Location'))) return $result;
        $url = Club_Url_Absolute($location, $url);
    } return false;
}

// 返回原因码，调用方要分开处置：这几种失败的自愈概率差好几个数量级，
// 共用一套退避阶梯的话，注销了域名的对端会被按「临时挂掉」每分钟重试：
//   ok         成功
//   failed     对端在，但没收下（curl 报错或 5xx）
//   unresolved 域名解析不出来，而本站 DNS 是好的
//   blocked    目标指向内网或协议不对，该拦
//   local-dns  本站自己解析不动，什么都没证明，不能算在对端头上
function ActivityPub_POST($url, $club, $jsonld) {
    // 队列里的 target 是很久以前拉取的，域名可能已经解析到内网，每次投递都要重判
    if (($public = Club_Url_Public($url)) === false) {
        Club_Log_Event('warning', 'push blocked, url is not public', ['url' => $url, 'club' => $club]);
        return 'blocked';
    }
    if ($public === null) {
        // 别的域名解析得动，就是这个对端把域名撤了/过期了，跟连不上没区别，照常记失败
        if (Club_Url_Resolve_Healthy()) {
            Club_Log_Event('info', 'push failed, host does not resolve', ['url' => $url, 'club' => $club]);
            return 'unresolved';
        }
        // 一个都解析不动，是本站自己的毛病
        Club_Log_Event('warning', 'push deferred, local dns looks broken', ['url' => $url, 'club' => $club]);
        return 'local-dns';
    }
    $date = gmdate('D, d M Y H:i:s T');
	$digest = base64_encode(hash('sha256', $jsonld, 1));
    return ActivityPub_CURL($url, $date, [
        'Signature' => ActivityPub_Signature($url, $club, $date, $digest),
        'Digest' => 'SHA-256='.$digest
    ], $jsonld, $public) === false ? 'failed' : 'ok';
}

// 黑名单探活只问一件事：对端还在不在。空 body 打 inbox 本来就会被 400/401 挡回来，
// 而 Curl 把 4xx 也算进 $error，照投递成功与否来判的话，进了黑名单的实例永远出不来。
// 这里只看有没有拿到状态码：拿到了就说明 DNS、TCP、TLS 到应用层全通。
// 5xx 不算，CDN 回源失败也是有状态码的，那种情况对端其实还是死的
function ActivityPub_Alive($url, $club) {
    global $curl;
    if (!is_array($public = Club_Url_Public($url))) {
        Club_Log_Event($public === null ? 'info' : 'warning', 'probe skipped, '
            .($public === null ? 'cannot resolve host' : 'url is not public'),
            ['url' => $url, 'club' => $club]);
        return false;
    }
    // 上面已经确认过一遍，这次 POST 必然会真的发出去，
    // $curl 里留的就是本次请求的结果，不会是上一次请求的残留
    ActivityPub_POST($url, $club, '{}');
    return isset($curl) && !$curl->curlError
        && $curl->httpStatusCode > 0 && $curl->httpStatusCode < 500;
}

function ActivityPub_CURL($url, $date, $head, $data = null, $ips = null) {
    global $ver, $base, $curl, $config; static $last_head = [], $pinned = null;
    if (!isset($curl)) $curl = new Curl();
    $curl->setTimeout(10);
    $curl->setConnectTimeout(3);
    // 内网检查的另一半：Club_Url_Public 只交上来公网地址，私网那些是靠
    // 「curl 拿不到就连不上」拦住的。不钉的话 curl 会拿 URL 自己再解析一遍，
    // 既把剔掉的地址捡回来，也留下 DNS rebinding 的空子。
    // 钉进去的条目在 curl 自己的 DNS 缓存里是永久的，所以每次先撤掉上一条，
    // 否则长期进程会越积越多，还会拿旧地址去连
    $resolve = [];
    if (isset($pinned)) { $resolve[] = '-'.$pinned; $pinned = null; }
    // URL 里直接写 IP 的不钉：本来就没有解析这一步，没有可乘之机，
    // 而且 host:port:addr 这个格式塞进一个 IPv6 字面量就分不出哪个冒号是分隔符了
    if ($ips && !empty(($parts = parse_url($url))['host'])
        && !filter_var($host = trim($parts['host'], '[]'), FILTER_VALIDATE_IP)) {
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
    Club_Log_Write('debug', 'curl', [
        isset($data) ? 'post' : 'get',
        strtolower($curl->responseHeaders['Status-Line'] ?? ''),
        preg_replace('#^https?://#i', '', $url)
    ], ['header' => $curl->responseHeaders, 'result' => $curl->response, 'error' => $curl->error]);
    return $curl->error ? false : ($curl->response ?: true);
}

function ActivityPub_Signature($url, $club, $date, $digest = null) {
    global $db, $base; $host = ($url_parts = parse_url($url))['host']; $path = '/';
	
	if (!empty($url_parts['path'])) $path = $url_parts['path'];
	if (!empty($url_parts['query'])) $path .= '?' . $url_parts['query'];
	
	$signed_string = "(request-target): ".(empty($digest)?'get':'post')." $path\nhost: $host\ndate: $date".(empty($digest)?'':"\ndigest: SHA-256=$digest");
	$pdo = $db->prepare('select `private_key` from `clubs` where `name` = :name');
    $pdo->execute([':name' => $club]);
    if ($pdo = $pdo->fetch(PDO::FETCH_ASSOC)) {
        openssl_sign($signed_string, $signature, $pdo['private_key'], OPENSSL_ALGO_SHA256);
        return 'keyId="'.$base.'/club/'.$club.'#main-key'.'",algorithm="rsa-sha256",headers="(request-target) host date'.(empty($digest)?'':' digest').'",signature="'.base64_encode($signature).'"';
    } return false;
}

function ActivityPub_Verification($input = null, $pull = true) {
    global $db, $verify_signed, $verify_actor;
    static $algos = ['rsa-sha256' => OPENSSL_ALGO_SHA256, 'hs2019' => OPENSSL_ALGO_SHA256, 'rsa-sha512' => OPENSSL_ALGO_SHA512];
    if (empty($_SERVER['HTTP_SIGNATURE'])) return ActivityPub_Verify_Fail('no signature header');
    $signature = [];
    preg_match_all('/[,\s]*(.*?)="(.*?)"/', $_SERVER['HTTP_SIGNATURE'], $matches);
    foreach ($matches[1] as $k => $v) $signature[$v] = $matches[2][$k];
    if (empty($signature['keyId']) || empty($signature['signature']) || empty($signature['headers']))
        return ActivityPub_Verify_Fail('malformed signature header');
    // algorithm 是对端给的，直接透传给 openssl 等于让对方挑摘要算法
    $algo = strtolower($signature['algorithm'] ?? 'rsa-sha256');
    if (!isset($algos[$algo])) return ActivityPub_Verify_Fail('unsupported algorithm: '.$algo);

    $post = strtolower($_SERVER['REQUEST_METHOD']) == 'post';
    // headers= 的顺序就是签名串的行顺序，(request-target) 不一定排在第一个
    $headers = explode(' ', strtolower($signature['headers']));
    if (!in_array('(request-target)', $headers)) return ActivityPub_Verify_Fail('(request-target) not signed');
    // date 或 (created) 必须签名并校验时效，否则签名可以被无限重放
    if (in_array('date', $headers)) {
        if (!ActivityPub_Date_Verify($_SERVER['HTTP_DATE'] ?? ''))
            return ActivityPub_Verify_Fail('date out of range: '.($_SERVER['HTTP_DATE'] ?? '-').' vs '.gmdate('D, d M Y H:i:s T'));
    } elseif (in_array('(created)', $headers)) {
        if (!ActivityPub_Date_Verify($signature['created'] ?? ''))
            return ActivityPub_Verify_Fail('(created) out of range: '.($signature['created'] ?? '-').' vs '.time());
    } else return ActivityPub_Verify_Fail('neither date nor (created) signed');
    if (in_array('(expires)', $headers) && ($signature['expires'] ?? PHP_INT_MAX) < time())
        return ActivityPub_Verify_Fail('signature expired at '.$signature['expires']);
    // POST 不签 digest 的话请求体可以被任意替换
    if ($post && !in_array('digest', $headers)) return ActivityPub_Verify_Fail('digest not signed');
    if ($post && empty($_SERVER['HTTP_DIGEST'])) return ActivityPub_Verify_Fail('digest header missing');

    $lines = [];
    foreach ($headers as $header) {
        switch ($header) {
            case '(request-target)':
                $lines[] = $header.': '.strtolower($_SERVER['REQUEST_METHOD']).' '.$_SERVER['REQUEST_URI']; break;
            case '(created)': case '(expires)':
                $lines[] = $header.': '.($signature[trim($header, '()')] ?? ''); break;
            default:
                // Content-Type / Content-Length 在 $_SERVER 里没有 HTTP_ 前缀
                $key = strtoupper(str_replace('-', '_', $header));
                if (!in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) $key = 'HTTP_'.$key;
                $lines[] = $header.': '.($_SERVER[$key] ?? '');
        }
    } $verify_signed = implode("\n", $lines);

    // keyId 一般是 actor 后面挂个片段，去掉片段就是 actor；片段名不一定叫 main-key，
    // 少数实现写成路径，所以末尾的 /main-key 也一并去掉
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
    return false;
}

// 签名只能证明「这个 keyId 的主人发的」。不比对 activity 的 actor 的话，
// 任何一个公钥入过库的远端用户都能冒充别人删号、退关、撤帖
function ActivityPub_Verify_Actor($actor) {
    global $verify_actor;
    if (empty($verify_actor) || $verify_actor !== $actor)
        return ActivityPub_Verify_Fail('actor mismatch: '.$actor.' vs '.($verify_actor ?: '-'));
    return true;
}

function ActivityPub_Verify_Fail($reason) {
    global $verify_reason; $verify_reason = $reason; return false;
}

// 把对端给的语言标记归一到 src/i18n/ 下支持的语言，认不出返回 false。
// 文件名直接用 Mastodon 那套地区写法（它的 config/locales 和前端 locales 也是 zh-CN / zh-TW / zh-HK），
// 这样内部 locale 就是对外发的标记，不用再转一道；script 写法留在下面当输入别名
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
    foreach (array_keys($object['contentMap']) as $lang)
        if ($match = Club_I18n_Match($lang)) return $match;
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

// 建库连接。worker 开多进程时，每个子进程 fork 完都要自己调一次重新建：
// fork 继承的是父进程那条连接的 socket，而领队列用的 last_insert_id() 是连接级状态，
// 共用一条会让两个子进程领到同一行，同一条投递发两遍。
// 持久连接只对 fpm 有意义（进程复用），CLI 下进程活着连接就活着，
// 而且持久连接的池子也会被 fork 继承，子进程再 new PDO 会直接拿回父进程那条
function Club_DB_Connect() {
    global $db, $config;
    $options = [PDO::ATTR_PERSISTENT => PHP_SAPI != 'cli', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    // worker 全程 autocommit，RC 和 RR 对它没有语义差别，但领队列那条 UPDATE 的
    // timestamp 过滤用不上索引，RR 下扫过的行全要上锁，几十个进程抢同一段索引就撞死锁。
    // RC 开着 semi-consistent read，不匹配的行当场放锁。web 端有事务，保持 RR 不动。
    // 空字符串不能留给 web：mysqlnd 会拿它当一条查询发出去，连上就报 Query was empty
    if (PHP_SAPI == 'cli')
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'set session transaction isolation level read committed';
    return $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'].';charset=utf8mb4',
        $config['mysql']['username'], $config['mysql']['password'], $options);
}

// 当前级别是否包含 $level。false 等同 silent，其他无法识别的取值按默认的 info 处理
function Club_Log_Level($level) {
    global $config; static $rank = ['silent' => 0, 'error' => 1, 'warning' => 2, 'info' => 3, 'debug' => 4];
    $set = $config['node']['log-level'] ?? 'info';
    if ($set === false) $set = 'silent';
    elseif (!is_string($set) || !isset($rank[$set = strtolower($set)])) $set = 'info';
    return $rank[$set] >= $rank[$level];
}

// 日志目录一律按 APP_ROOT 建：worker 的工作目录不一定是项目根目录，
// 用相对路径会把目录建在别处，而写日志用的是绝对路径，等于白建
function Club_Log_Dir($dir = '') {
    // 父目录单独建：用 mkdir 的递归模式的话，中间层 logs/ 是 mkdir 内部建的，
    // 拿不到下面那次 chmod，会停在被 umask 削过的权限上
    if ($dir !== '' && !Club_Log_Dir()) return false;
    $path = APP_ROOT.'/logs'.($dir === '' ? '' : '/'.$dir);
    // web 和 worker 通常不是同一个用户，谁建的目录另一方都要能往里写、能删里面的文件
    //（unlink 看的是目录权限，不是文件权限）
    if (!is_dir($path)) {
        // 并发请求可能同时建同一个目录，mkdir 失败后再确认一次。
        // 留着递归是为了 APP_ROOT 本身还不存在的情况，logs/ 这一层已经由上面那次调用单独建过
        if (!@mkdir($path, 0777, true) && !is_dir($path)) return false;
        // mkdir 的 mode 一样会被 umask 削掉，只能补一次 chmod
        @chmod($path, 0777);
    // 已经存在的目录也校一次：老部署留下的是 0755，不自己修就得人工 chmod
    } elseif ((fileperms($path) & 0777) !== 0777) @chmod($path, 0777);
    return $path;
}

// 日志文件的实际落盘。同样是跨用户的问题：logs/event/ 和 logs/error/ 按天追加，
// web 用户先建出 0644 的文件后 worker 用户连 append 都会失败，比删不掉更早发作
function Club_Log_Put($file, $data, $flags = 0) {
    $new = !is_file($file);
    if (@file_put_contents($file, $data, $flags) === false) return false;
    // 已存在的也要校一次，不能假设「存在就说明对方建的时候 chmod 过了」：
    // logs/error/ 下的文件是 PHP 引擎自己建的，从来没经过这里；老部署留下的也是 0644。
    // chmod 只有属主能调，不是自己的文件这一步会静默失败，但那时对方多半已经放开了
    if ($new || (fileperms($file) & 0777) !== 0666) @chmod($file, 0666);
    return true;
}

// 文件名片段清洗。片段来源里 status-line、url、webfinger 的 resource 都是对端可控的：
// 路径分隔符换成形近的 Ⳇ 保留可读性，空白并成 _，控制字符和 glob 元字符去掉
//（去重时要拿基名当 glob 模式用）。不加 u 修饰符，对端发来非法 UTF-8 时按字节处理才不会整个变空
function Club_Log_Slug($part) {
    $part = str_replace(['/', '\\'], 'Ⳇ', (string)$part);
    return preg_replace(['/\s+/', '/[\x00-\x1f\x7f*?\[\]]/'], ['_', ''], $part);
}

// 生成一组日志文件共用的基名，同一事件的多个文件靠它配对
function Club_Log_Name($dir, $parts) {
    if (!Club_Log_Level('error') || !($path = Club_Log_Dir($dir))) return '';
    // 时分秒之间用 - 不用 :，冒号在 NTFS 上是非法字符，写文件会直接失败
    $name = date('Y-m-d_H-i-s');
    foreach (to_array($parts) as $part)
        if (($part = Club_Log_Slug($part)) !== '') $name .= '_'.$part;
    // 文件名上限 255 字节，url 和 resource 能轻松撑爆，留出后缀和序号的余量
    $name = $base = substr($name, 0, 180);
    // 时间戳只到秒，同一秒的同名事件会互相覆盖（撤回提醒成批发出，必然撞），占用了就往后排
    for ($i = 1; $i < 100 && glob($path.'/'.$name.'*'); $i++) $name = $base.'-'.$i;
    return $name;
}

// 写日志的唯一入口：级别不够直接跳过，目录按需建。
// $name 传数组表示这条日志独占一个基名，传字符串则是 Club_Log_Name 的结果加后缀
function Club_Log_Write($level, $dir, $name, $data, $ext = 'json') {
    if (!Club_Log_Level($level)) return false;
    if (is_array($name)) $name = Club_Log_Name($dir, $name);
    if ($name === '' || !($path = Club_Log_Dir($dir))) return false;
    return Club_Log_Put($path.'/'.$name.($ext === '' ? '' : '.'.$ext),
        is_string($data) ? $data : Club_Json_Encode($data));
}

// PHP 自己写的 error log 的目标文件。worker 是长期进程，不会重新走 bootstrap：
// 跨天后它还指着启动那天的文件，等 rotate 把那个删掉，引擎会自己重建一个，权限也就丢了
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

// 当前这次处理的关联标记，值就是 logs/inbox/ 或 logs/outbox/ 下那组文件的基名。
// event 里每行都带上它，看到可疑的一行可以直接 ls logs/inbox/<标记>* 把报文捞出来。
// 同一秒进来好几条活动是常态，只靠 event 的时间戳对不上具体是哪一条
function Club_Log_Ref($ref = null) {
    static $current = '';
    if (isset($ref)) $current = $ref;
    return $current;
}

// 低频事件写成按天追加的一个文件：拉黑、建群、销号这类一行就说清楚的事，
// 按事件切成一堆 JSON 文件反而难查。跟 logs/error/ 分开，那边只留 PHP 引擎自己的报错
function Club_Log_Event($level, $message, $context = []) {
    if (!Club_Log_Level($level) || !($path = Club_Log_Dir('event'))) return false;
    if ($ref = Club_Log_Ref()) $message = $ref.' '.$message;
    if ($context) $message .= ' '.Club_Json_Encode($context);
    // 一条事件一行，消息里的换行要压掉，否则 grep 出来只有半句
    return Club_Log_Put($path.'/'.date('Y-m-d').'.log',
        date('[Y-m-d H:i:s] ').strtoupper($level).' '.preg_replace('/\s+/', ' ', $message)."\n",
        FILE_APPEND);
}

// worker 的输出既要实时给人看，也要留档，两边共用一个入口。
// 上下文照着 Club_Log_Event 的样子拼在消息后面，终端和 event 日志里是同一行；
// 多进程模式下几个进程的输出混在一起，靠这里的 pid 才分得清哪句是谁说的
function Club_Log_Console($level, $message, $context = []) {
    if (PHP_SAPI == 'cli')
        echo date('[Y-m-d H:i:s]').' '.$message.($context ? ' '.Club_Json_Encode($context) : ''), "\n";
    Club_Log_Event($level, $message, $context);
}

// logs/ 按请求写文件，长期跑会占满 inode，由 worker 空闲时清理
function Club_Log_Rotate($days, $interval = 3600) {
    static $last = 0;
    if ($days < 1 || $last > time() - $interval || !is_dir($root = APP_ROOT.'/logs')) return;
    $last = time(); $expire = time() - $days * 86400;
    foreach (glob($root.'/*', GLOB_ONLYDIR) as $dir)
        foreach (glob($dir.'/*') as $file)
            if (is_file($file) && filemtime($file) < $expire) unlink($file);
}

function ActivityPub_Date_Verify($date, $skew = 300) {
    if (empty($date)) return false;
    // Date 头是 HTTP 日期，(created) 是 unix 时间戳
    $time = ctype_digit((string)$date) ? (int)$date : strtotime($date);
    return $time && abs(time() - $time) <= $skew;
}

function ActivityPub_Digest_Verify($input) {
    if (!preg_match('/([A-Za-z0-9-]+)\s*=\s*([A-Za-z0-9+\/=]+)/', $_SERVER['HTTP_DIGEST'], $matches))
        return ActivityPub_Verify_Fail('malformed digest header');
    $algo = strtolower(str_replace('-', '', $matches[1]));
    if (!in_array($algo, ['sha256', 'sha512'])) return ActivityPub_Verify_Fail('unsupported digest algorithm: '.$matches[1]);
    if (!hash_equals(hash($algo, (string)$input, 1), base64_decode($matches[2])))
        return ActivityPub_Verify_Fail('digest mismatch, body length '.strlen((string)$input));
    return true;
}

function Club_Exist($club) {
    global $db, $config, $club_reason; $club_reason = null;
    if (strlen($club) > 30 || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]+$/u', $club))
        return Club_Exist_Fail('invalid name');
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

// 发私信提醒和对外拉取都用它，不开放注册、不进目录、不接受关注
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
    $key = openssl_pkey_new([
		'digest_alg' => 'sha512',
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA
	]);
    if (!$key || !openssl_pkey_export($key, $priv_key)) {
        Club_Log_Event('error', 'club keygen failed: '.$club.', '.openssl_error_string());
        return Club_Exist_Fail('keygen failed');
    }
    $detail = openssl_pkey_get_details($key);
    $pdo = $db->prepare('insert into `clubs`(`name`,`public_key`,`private_key`,`timestamp`) values(:name, :public, :private, :timestamp)');
    try { $pdo->execute([':name' => $club, ':public' => $detail['key'], ':private' => $priv_key, ':timestamp' => time()]); }
    catch (PDOException $e) { /* 并发建群撞唯一键，下面重查一次 */ }
    $pdo = $db->prepare('select `name` from `clubs` where `name` = :name');
    $pdo->execute([':name' => $club]);
    return $pdo->fetch(PDO::FETCH_COLUMN, 0);
}

function Club_Get_Actor($club, $actor) {
    global $db; $pdo = $db->prepare('select `uid`,`name`,`inbox`,`shared_inbox` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    return $pdo->fetch(PDO::FETCH_ASSOC) ?: Club_Fetch_Actor($club, $actor);
}

// 拉取远端 actor 写入本地缓存，已存在则更新（对方可能轮换密钥或迁移 inbox）
function Club_Fetch_Actor($club, $actor) {
    global $db; $jsonld = json_decode(ActivityPub_GET($actor, $club), 1);
    if (empty($jsonld['id']) || $jsonld['id'] != $actor || empty($jsonld['inbox'])) {
        Club_Log_Event('warning', 'fetch actor failed: '.$actor);
        return false;
    }
    $data = [
        ':name' => ($jsonld['preferredUsername'] ?? '').'@'.parse_url($jsonld['id'], PHP_URL_HOST),
        ':inbox' => $jsonld['inbox'], ':public_key' => $jsonld['publicKey']['publicKeyPem'] ?? '',
        ':shared_inbox' => $jsonld['endpoints']['sharedInbox'] ?? $jsonld['inbox'],
        ':actor' => $jsonld['id'], ':timestamp' => time()
    ];
    $pdo = $db->prepare('select `uid` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    if ($pdo->fetch(PDO::FETCH_COLUMN, 0))
        $pdo = $db->prepare('update `users` set `name` = :name, `inbox` = :inbox, `public_key` = :public_key,'.
            ' `shared_inbox` = :shared_inbox, `refresh` = :timestamp where `actor` = :actor');
    else
        $pdo = $db->prepare('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`,`refresh`)'.
            ' values (:name, :actor, :inbox, :public_key, :shared_inbox, :timestamp, :timestamp)');
    try { $pdo->execute($data); }
    catch (PDOException $e) { /* 并发写入撞 actor 唯一键，下面重查一次 */ }
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
    global $db; $pdo = $db->prepare('select `refresh` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    $refresh = $pdo->fetch(PDO::FETCH_COLUMN, 0);
    // 冷却中就不再拉。验签失败又刷不了公钥时，这行是唯一的解释
    if ($refresh !== false && $refresh > time() - $cooldown) {
        Club_Log_Event('debug', 'actor refresh on cooldown', ['actor' => $actor,
            'retry in' => ($refresh + $cooldown - time()).'s']);
        return false;
    }
    return ($club = Club_System()) ? Club_Fetch_Actor($club, $actor) : false;
}

function Club_Task_Create($type, $club, $jsonld) {
    global $db;
    $pdo = $db->prepare('insert into `tasks`(`cid`,`type`,`jsonld`,`timestamp`) select `cid`, :type as `type`, :jsonld as `jsonld`, :timestamp as `timestamp` from `clubs` where `name` = :club');
    $pdo->execute([':type' => $type, ':club' => $club, ':jsonld' => $jsonld, ':timestamp' => time()]);
    // 群组不存在时 insert-select 不写入任何行，此时 last_insert_id() 是上一条的残值
    return $pdo->rowCount() ? $db->lastInsertId() : false;
}

function Club_Queue_Insert($task, $target) {
    global $db;
    $pdo = $db->prepare('insert into `queues`(`tid`,`target`,`timestamp`)'.
        ' select :tid, :target, :timestamp from dual where :check not in (select `target` from `blacklist`)');
    $pdo->execute([':tid' => $task, ':target' => $target, ':check' => $target, ':timestamp' => time()]);
    // 写不进去只有一个原因：目标在黑名单里。直接投递（Accept、私信）被静默丢掉很难查
    if (!$pdo->rowCount()) Club_Log_Event('debug', 'push target is blacklisted', ['target' => $target]);
    return Club_Queue_Count($task, $pdo->rowCount());
}

// 一次性把关注者的 shared_inbox 入队，避免按关注者数量逐条往返
function Club_Queue_Insert_Followers($task, $club, $inbox = false) {
    global $db;
    $params = [':tid' => $task, ':club' => $club, ':timestamp' => time()];
    if ($inbox !== false) $params[':inbox'] = $inbox;
    $pdo = $db->prepare('insert into `queues`(`tid`,`target`,`timestamp`) select :tid, `t`.`target`, :timestamp from ('.
        ' select distinct u.shared_inbox as `target` from `followers` `f`'.
        ' join `clubs` `c` on f.cid = c.cid join `users` `u` on f.uid = u.uid where c.name = :club'.
        ($inbox === false ? '' : ' union select :inbox as `target`').
        ') `t` where `t`.`target` not in (select `target` from `blacklist`)');
    $pdo->execute($params);
    return Club_Queue_Count($task, $pdo->rowCount());
}

function Club_Queue_Count($task, $count) {
    global $db; if (!$count) return true;
    $pdo = $db->prepare('update `tasks` set `queues` = `queues` + :count where `tid` = :tid');
    return $pdo->execute([':tid' => $task, ':count' => $count]);
}

// 数组是本站自己组的活动，字符串是原样透传的远端活动（$type 由调用方给）。
// 透传的那份绝不能解码后重新编码：RsaSignature2017 签的是整包规范化的结果，
// 而 json_decode 的关联数组模式会把 {} 变成 []，再编码出来签名就废了
function Club_Push_Activity($club, $activity, $inbox = false, $direct = false, $type = null) {
    global $db, $config;
    if (is_array($activity)) { $type = $activity['type']; $activity = Club_Json_Encode($activity); }
    $name = Club_Log_Name('outbox', [$club, $type ?: 'unknown']);
    Club_Log_Write('info', 'outbox', $name.'_output', $activity);
    Club_Log_Write('debug', 'outbox', $name.'_server', $_SERVER);
    $commit = false; $error = '';
    try {
        $db->beginTransaction();
        if ($task = Club_Task_Create('push', $club, $activity)) {
            if ($direct) Club_Queue_Insert($task, $inbox);
            else Club_Queue_Insert_Followers($task, $club, $inbox);
            $commit = $db->commit();
        } else $error = 'club not found: '.$club;
    } catch (PDOException $e) { $error = $e->getMessage(); Club_Log_Event('error', 'push failed: '.$error); }
    if (!$commit) {
        Club_Log_Write('error', 'outbox', $name.'_commit_failed', $error ?: 'commit returned false', 'txt');
        if ($db->inTransaction()) $db->rollback();
    // 带上 outbox 那组文件的基名：入站活动的关联标记是 inbox 的，跳不到发出去的报文
    } else Club_Log_Event('debug', 'push queued, '.($type ?: 'unknown'),
        ['club' => $club, 'target' => $direct ? $inbox : 'followers', 'outbox' => $name]);
    return (bool)$commit;
}

// 单条规则写成关联数组，多条写成列表
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
        $pdo = $db->prepare('select count(a.id) from `announces` `a` join `clubs` `c` on a.cid = c.cid'.
            ' join `users` `u` on a.uid = u.uid where c.name = :club and a.timestamp >= :timestamp'.$where);
        $pdo->execute($params);
        if ($pdo->fetch(PDO::FETCH_COLUMN, 0) >= $limit) return ['limit-'.$type, $vars];
    }
    return false;
}

// 用系统群组发私信提醒。传了 $reply 就作为那条帖子的回复发出，每条都回；
// 不针对具体帖子的提醒才做冷却，避免刷屏
function Club_Notice_Send($actor, $type, $vars = [], $lang = null, $reply = null, $cooldown = 3600) {
    global $db, $base, $config;
    if (!($config['notice']['enabled'] ?? true) || empty($actor)) return false;
    if (!($club = Club_System()) || !($user = Club_Get_Actor($club, $actor))) return false;

    if (!isset($reply)) {
        $pdo = $db->prepare('select max(`timestamp`) from `notices` where `uid` = :uid and `type` = :type');
        $pdo->execute([':uid' => $user['uid'], ':type' => $type]);
        $last = $pdo->fetch(PDO::FETCH_COLUMN, 0);
        // 用户报「没收到提醒」时，这两条决定了是发不出去还是压根没发
        if ($last && $last > time() - $cooldown) {
            Club_Log_Event('debug', 'notice on cooldown, '.$type,
                ['actor' => $actor, 'retry in' => ($last + $cooldown - time()).'s']);
            return false;
        }
    } else {
        // 逐条回复对刷屏用户等于反向刷屏，每人每天封顶
        $pdo = $db->prepare('select count(`id`) from `notices` where `uid` = :uid and `timestamp` >= :timestamp');
        $pdo->execute([':uid' => $user['uid'], ':timestamp' => time() - 86400]);
        if (($sent = $pdo->fetch(PDO::FETCH_COLUMN, 0)) >= ($limit = $config['notice']['limit'] ?? 20)) {
            Club_Log_Event('debug', 'notice daily limit reached, '.$type,
                ['actor' => $actor, 'sent' => $sent, 'limit' => $limit]);
            return false;
        }
    }
    $pdo = $db->prepare('insert into `notices`(`uid`,`type`,`object`,`timestamp`) values (:uid, :type, :object, :timestamp)');
    $pdo->execute([':uid' => $user['uid'], ':type' => $type, ':object' => $reply, ':timestamp' => ($time = time())]);
    if (!($id = $db->lastInsertId())) return false;

    $club_url = $base.'/club/'.$club;
    $note_url = $club_url.'/notice/'.$id;
    // 记下 Note 地址，用户删帖时照它撤回
    $pdo = $db->prepare('update `notices` set `note` = :note where `id` = :id');
    $pdo->execute([':id' => $id, ':note' => $note_url]);

    $locale = Club_I18n_Locale($lang);
    $content = '<p><a href="'.$actor.'" class="u-url mention">@'.$user['name'].'</a> '.Club_I18n($type, $locale, $vars).'</p>';

    // to 只有收件人、cc 为空，Mastodon 据此判定为私信
    Club_Push_Activity($club, [
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
    return true;
}

// 用户删帖时把针对这条帖子的提醒一并撤回，不留孤儿回复。
// 限定 actor，不然别人报一个帖子 id 就能把发给你的提醒清掉
function Club_Notice_Delete($object, $actor) {
    global $db;
    $pdo = $db->prepare('select n.id, n.note, u.actor, u.inbox from `notices` `n`'.
        ' join `users` `u` on n.uid = u.uid where n.object = :object and u.actor = :actor and n.note is not null');
    $pdo->execute([':object' => $object, ':actor' => $actor]);
    Club_Notice_Revoke($pdo->fetchAll(PDO::FETCH_ASSOC));
}

// 只删本地记录的话对端会永远留着那条私信，所以要撤回；每轮限量，避免积压压垮队列
function Club_Notice_Expire($days = 30, $limit = 20, $interval = 600) {
    global $db; static $last = 0;
    if ($days < 1 || $last > time() - $interval) return;
    $last = time(); $expire = time() - $days * 86400;
    $pdo = $db->prepare('select n.id, n.note, u.actor, u.inbox from `notices` `n`'.
        ' join `users` `u` on n.uid = u.uid where n.timestamp <= :timestamp and n.note is not null limit '.(int)$limit);
    $pdo->execute([':timestamp' => $expire]);
    Club_Notice_Revoke($pdo->fetchAll(PDO::FETCH_ASSOC));
    // note 为空的行没发出去过，不用通知对端
    $pdo = $db->prepare('delete from `notices` where `timestamp` <= :timestamp and `note` is null');
    $pdo->execute([':timestamp' => $expire]);
}

function Club_Notice_Revoke($notices) {
    global $db, $base;
    if (!$notices || !($club = Club_System())) return;
    $club_url = $base.'/club/'.$club;
    foreach ($notices as $notice) {
        Club_Push_Activity($club, [
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
        $pdo = $db->prepare('delete from `notices` where `id` = :id');
        $pdo->execute([':id' => $notice['id']]);
    }
}

// 群组 inbox 和 shared inbox 共用，避免两处日志走偏。基名由调用方算好传进来：
// 它同时是 event 里的关联标记，两边必须是同一个值
function Club_Log_Inbox($name, $input, $verify) {
    global $verify_reason, $verify_signed;
    // 验签没过时正文跟着降到 warning 一起留：只有失败原因没有请求体，排查时等于少了一半
    Club_Log_Write($verify ? 'info' : 'warning', 'inbox', $name.'_input', $input);
    Club_Log_Write('debug', 'inbox', $name.'_server', $_SERVER);
    if (!$verify) Club_Log_Write('warning', 'inbox', $name.'_verify_failed',
        'reason: '.($verify_reason ?? '-')."\n\nsignature: ".($_SERVER['HTTP_SIGNATURE'] ?? '-').
        "\n\ndigest: ".($_SERVER['HTTP_DIGEST'] ?? '-')."\n\nsigned string:\n".($verify_signed ?? '-'), 'txt');
}

// inbox 链路上每个提前 return 的去向。这条链上十几个 return 从外面看长得一模一样：
// 没数据、没报错、logs/inbox/ 里躺着一个正常的 _input 文件，只能回源码里数 return。
// 写法沿用 Club_Exist_Fail 那套，在返回点直接 return Club_Inbox_Skip(...)
function Club_Inbox_Skip($reason, $context = []) {
    Club_Log_Event('debug', 'inbox skip, '.$reason, $context);
}

// inbox 的对外入口。DB 抛异常必须在 event 里也留一行，否则那边是「inbox in」之后
// 戛然而止，判断不出是没匹配到分支还是中途挂了。
// 500 会让对端重投，这对临时性的 DB 故障是对的行为，跟未捕获时一致
function Club_Inbox_Process($input, $club = null) {
    try { Club_Inbox_Dispatch($input, $club); }
    catch (PDOException $e) {
        Club_Log_Event('error', 'inbox aborted, database error', ['error' => $e->getMessage()]);
        // 中断可能发生在 Club_Log_Inbox 之前，那样 event 里的关联标记会指向一个不存在的文件。
        // 报文是查这类中断的唯一依据，非补不可；已经写过的话同名覆盖，无害
        if ($name = Club_Log_Ref()) Club_Log_Write('error', 'inbox', $name.'_input', $input);
        // 前面可能已经输出过 400，重复 header 会触发警告
        if (!headers_sent()) Club_Json_Output(['message' => 'Internal error'], 0, 500);
    }
}

// 群组 inbox 和 shared inbox 走同一套流程，$club 为 null 表示 shared inbox
function Club_Inbox_Dispatch($input, $club = null) {
    global $db, $config, $verify_reason;
    $jsonld = is_array($jsonld = json_decode($input, 1)) ? $jsonld : [];
    // 顶层数组是合法 JSON-LD（node object 的数组），Foundkey 一类实现这么发。
    // 单个活动拆开照常处理；多元素是活动集合，只认第一条等于把其余的静默丢掉，那种不收
    $wrapped = count($jsonld) === 1 && isset($jsonld[0]) && is_array($jsonld[0]);
    if ($wrapped) $jsonld = $jsonld[0];
    // 拆过的包不能再转发：原始字节仍是数组形态，下游多半跟我们一样只认单个对象。
    // 清掉之后走的就是对端没签 LD 签名时那条路 —— 本站照删、群发 Undo 撤回，只是不转原报文。
    // $input 得留着，日志里那份要的是对端发来的原样字节
    $payload = $wrapped ? null : $input;
    // type 会进日志文件名，不限成纯字母的话对端能用 ../ 穿出 logs 目录
    $type = is_string($t = $jsonld['type'] ?? '') && preg_match('/^[A-Za-z]+$/', $t) ? $t : '';
    $actor = Club_Object_Id($jsonld['actor'] ?? '');
    // actor 必须是外站的绝对地址：本站自己的 activity 不该从 inbox 进来，不是 URL 的也没法验签
    $host = $actor === '' ? '' : (string)parse_url($actor, PHP_URL_HOST);

    // 基名一次请求只算一次：它既是 logs/inbox/ 下那组文件的前缀，也是 event 里的关联标记，
    // 两边必须完全一致，否则从 event 定位报文时对不上。销号那条多带一段方便肉眼筛
    $parts = [$club ?? 'shared_inbox', $type ?: 'unknown'];
    if ($type === 'Delete' && $actor !== '' && $actor === Club_Object_Id($jsonld['object'] ?? ''))
        $parts[] = 'actor';
    Club_Log_Ref($name = Club_Log_Name('inbox', $parts));
    // 转发那一半会因此降级，不留痕的话事后看不出这条为什么只撤回没转发
    if ($wrapped) Club_Log_Event('debug', 'inbox unwrapped json-ld array, relay disabled',
        ['club' => $club ?? 'shared', 'type' => $type ?: 'unknown']);

    if ($type === '' || $host === '' || strcasecmp($host, $config['base']) === 0) {
        // 这三种都进不了验签，不留痕的话冒充本站身份这种明显的攻击特征就完全看不到
        $reason = $type === '' ? 'invalid type: '.substr((string)($jsonld['type'] ?? ''), 0, 100)
            : ($host === '' ? 'actor is not a url: '.substr($actor, 0, 200)
            : 'actor claims local host: '.substr($actor, 0, 200));
        Club_Log_Write('warning', 'inbox', $name.'_input', $input);
        Club_Log_Write('warning', 'inbox', $name.'_rejected', $reason, 'txt');
        Club_Log_Event('warning', 'inbox rejected, '.$reason, ['club' => $club ?? 'shared']);
        return Club_Json_Output(['message' => 'Request is invalid'], 0, 400);
    }
    $jsonld['actor'] = $actor;
    // 每条入站活动在 event 里留一行，logs/inbox/ 是按请求切的文件，翻不出时间线
    Club_Log_Event('debug', 'inbox in, '.$type, ['club' => $club ?? 'shared',
        'actor' => $actor, 'object' => Club_Object_Id($jsonld['object'] ?? '')]);

    // 销号和改资料是广播给所有见过的实例的，绝大多数是我们从没见过的用户。这两类都只作用于
    // actor 自己，本地没缓存过它就是空操作：验签纯属白烧一次 RSA，还会记一堆 unknown actor 的
    // 失败日志（Update 那条更糟，默认会顺带触发一次拉取）。判据与 Mastodon 的
    // skip_unknown_actor_activity 一致，同样放在验签之前
    if (in_array($type, ['Delete', 'Update'], true)
        && $actor === Club_Object_Id($jsonld['object'] ?? '') && !Club_Has_Actor($actor)) {
        // 这条路不验签也不走 Club_Log_Inbox，debug 下得自己补一份报文，
        // 否则 event 里这条 skip 的关联标记在 logs/inbox/ 下找不到对应文件
        Club_Log_Write('debug', 'inbox', $name.'_input', $input);
        return Club_Inbox_Skip('broadcast from actor we never cached', ['type' => $type, 'actor' => $actor]);
    }

    // 对端注销账号，清掉本地缓存，关注关系靠外键级联删除
    if ($type == 'Delete' && $actor === Club_Object_Id($jsonld['object'] ?? '')) {
        $verify = ActivityPub_Verification($input, false) && ActivityPub_Verify_Actor($actor);
        // 销号会连带级联删掉关注关系，是破坏性最大的一条，成没成都要留痕
        Club_Log_Inbox($name, $input, $verify);
        if ($verify) {
            $pdo = $db->prepare('delete from `users` where `actor` = :actor');
            $pdo->execute([':actor' => $actor]);
            Club_Log_Event('info', 'actor deleted: '.$actor.', '.$pdo->rowCount().' row(s)');
        } return;
    }
    $verify = ActivityPub_Verification($input) && ActivityPub_Verify_Actor($actor);
    Club_Log_Inbox($name, $input, $verify);
    if (($config['node']['inbox-verify'] ?? true) && !$verify)
        // 详情在 logs/inbox/*_verify_failed 里，这里只留一行好对时间线
        return Club_Inbox_Skip('verification failed', ['actor' => $actor, 'reason' => $verify_reason ?? '-']);
    if (!$verify) Club_Log_Event('warning', 'inbox unverified but accepted, inbox-verify is off',
        ['actor' => $actor, 'reason' => $verify_reason ?? '-']);
    // 系统群组只负责发私信，不接受关注也不转发投稿
    if (isset($club) && Club_System_Name($club))
        return Club_Inbox_Skip('system club accepts nothing', ['club' => $club, 'type' => $type]);

    switch ($type) {
        case 'Create': Club_Announce_Process($jsonld); break;
        case 'Follow': Club_Follow_Process($jsonld); break;
        case 'Undo': Club_Undo_Process($jsonld); break;
        // 转发要发原始报文，$jsonld 这边被归一化过（actor 拍平、Tombstone 补形状），不能拿去发
        case 'Update': Club_Update_Process($jsonld, $payload); break;
        case 'Delete':
            // object 可以是内嵌的 Tombstone，也可以直接是被删对象的 id
            if (!isset($jsonld['object']['type']))
                $jsonld['object'] = ['id' => Club_Object_Id($jsonld['object'] ?? ''), 'type' => 'Tombstone'];
            if ($jsonld['object']['type'] == 'Tombstone') Club_Tombstone_Process($jsonld, $payload);
            else Club_Inbox_Skip('delete of non-tombstone', ['object type' => $jsonld['object']['type']]);
            break;
        default: Club_Inbox_Skip('no handler for type', ['type' => $type, 'actor' => $actor]);
    }
}

function Club_Announce_Process($jsonld) {
    global $db, $base, $config, $public_streams, $club_reason;
    if (!is_array($jsonld['object'] ?? null) || !($object = Club_Object_Id($jsonld['object']['id'] ?? '')))
        return Club_Inbox_Skip('create without object id', ['actor' => $jsonld['actor']]);
    // object 必须属于发送者，否则能让群组替别人转发他的帖子
    $author = Club_Object_Id($jsonld['object']['attributedTo'] ?? '');
    if ($author ? $author !== $jsonld['actor']
        : parse_url($object, PHP_URL_HOST) !== parse_url($jsonld['actor'], PHP_URL_HOST)) {
        // 验签是过的，所以 inbox 那边不会有 verify_failed，这里不记就彻底看不见
        Club_Log_Event('warning', 'announce author mismatch',
            ['actor' => $jsonld['actor'], 'author' => $author, 'object' => $object]);
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
                elseif ($club_reason == 'create limited')
                    Club_Notice_Send($jsonld['actor'], 'create-limit', [], $lang);
            }
        if (!empty($clubs) && ($clubs = array_keys($clubs)) && in_array($public_streams, $to)) {
            if ($actor = Club_Get_Actor($clubs[0], $jsonld['actor'])) {
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
                            Club_Log_Event('info', 'announce filtered, '.$reject[0],
                                ['club' => $club, 'actor' => $jsonld['actor'], 'object' => $object]);
                            // 一条帖子只回一次，即使撞了多个群组的规则
                            if (empty($notified) && ($notified = true))
                                Club_Notice_Send($jsonld['actor'], $reject[0], $reject[1], $lang, $object);
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
                        $pdo = $db->prepare('insert ignore into `announces`(`cid`,`uid`,`activity`,`summary`,`content`,`timestamp`)'.
                            ' select `cid`, :uid as `uid`, :activity as `activity`, :summary as `summary`, :content as `content`, :timestamp as `timestamp` from `clubs` where `name` = :club');
                        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':activity' => $activity_id,
                            ':summary' => $summary, ':content' => $content, ':timestamp' => $published]);
                    }
                    // 只保留真正转发出去的群组，撤回时才不会对没转发过的发 Undo
                    if ($announced != $clubs) {
                        $pdo = $db->prepare('update `activities` set `clubs` = :clubs where `id` = :id');
                        $pdo->execute([':id' => $activity_id, ':clubs' => Club_Json_Encode($announced)]);
                    }
                    // 一条投稿最终落到哪几个群组，是这整条链上唯一值得在 info 级别看见的结果
                    Club_Log_Event($announced ? 'info' : 'warning',
                        'announce '.($announced ? 'queued' : 'produced nothing'),
                        ['object' => $object, 'clubs' => $announced,
                         'skipped' => array_values(array_diff($clubs, $announced))]);
                // 同一条内容同时投到群组 inbox 和 shared inbox 时，输的那次走这里
                } else Club_Inbox_Skip('create already claimed by a concurrent delivery', ['object' => $object]);
            } else Club_Json_Output(['message' => 'Actor not found'], 0, 400);
        // $clubs 在上面的条件里已经被 array_keys 转成列表了，没进那步则还没定义
        } else Club_Inbox_Skip('create not addressed to a club, or not public',
            ['object' => $object, 'clubs' => $clubs ?? [], 'to' => $to]);
    } else Club_Inbox_Skip('create already processed', ['object' => $object]);
}

// 转发的两条共同前提。
// 一是得带 LD 签名：对端只能从 HTTP 签名验到群组，验不到原作者，没签名的转过去必被丢弃。
// 二是不能大到离谱：转发存进 tasks.jsonld 的是对端完全可控的原始字节，出队时按关注实例数
// 逐个扇出。2 万字的中文投稿约 118 KB，乘上千个实例就是上百 MB 出站，不封顶等于开放放大器。
// 上限默认写死在代码里，config.php 没同步过去时也得有个数
function Club_Relay_Allow($jsonld, $input, $object, $type) {
    global $config; $type = strtolower($type);
    if (empty($jsonld['signature']['signatureValue'])) {
        Club_Log_Event('info', $type.' not relayed, no ld signature', ['object' => $object]);
        return false;
    }
    if (($size = strlen($input)) > ($limit = ($config['club']['relay-limit'] ?? 512) * 1024)) {
        Club_Log_Event('warning', $type.' not relayed, payload too large',
            ['object' => $object, 'size' => $size, 'limit' => $limit]);
        return false;
    }
    return true;
}

// 原作者编辑帖子后，Mastodon 只把 Update 发给自己的关注者。A 和 B 不在同一实例、之间也没有关注
// 关系时，B 只是通过群组的 Announce 拿到的原帖，收不到这条 Update，本地那份就永远停在旧版本。
// Update 自带 RsaSignature2017，对端验得出原作者，所以整包原样转出去即可
function Club_Update_Process($jsonld, $input) {
    global $db;
    if (!is_array($object = $jsonld['object'] ?? null) || !($id = Club_Object_Id($object['id'] ?? '')))
        return Club_Inbox_Skip('update without object id', ['actor' => $jsonld['actor']]);
    // updated 是唯一的单调量，没有它就没法判重，同一包被重放几次就会往外扇几次
    if (!($updated = strtotime(is_string($u = $object['updated'] ?? '') ? $u : '')))
        return Club_Inbox_Skip('update without a usable updated field', ['object' => $id]);
    // 「这条帖子本站真的 Announce 过」加「发送者就是原作者」，两条合起来就是准入闸门：
    // 入站验签已经证明 HTTP 签名属于 $jsonld['actor']，这里再确认那个 actor 正是这条帖子的作者。
    // 少了它，任何人往 inbox 推一包活动都能改本站的记录、并被扇到全部关注者。
    // 注意这已经足够「我们自己」相信作者，所以本地落库照做；LD 签名是给第三方验的，
    // 只决定能不能转发出去，不该拦住本地那一半——否则 GtS 一类不签名的实现连列表页都跟不上
    $pdo = $db->prepare('select a.id, a.clubs from `activities` `a` join `users` `u` on a.uid = u.uid'.
        ' where a.object = :object and a.type = :type and u.actor = :actor');
    $pdo->execute([':object' => $id, ':type' => 'Create', ':actor' => $jsonld['actor']]);
    // 本站没转发过这条帖子，或者发送者不是原作者。后者在正常联邦里不该出现，值得看见
    if (!($activity = $pdo->fetch(PDO::FETCH_ASSOC)))
        return Club_Inbox_Skip('update for a post we never announced, or not from its author',
            ['actor' => $jsonld['actor'], 'object' => $id]);
    // 判重和占位必须是同一条语句：同一条 Update 会同时进群组 inbox 和 shared inbox，
    // 先 select 再 update 的话两边都会通过。比本地旧的也在这里挡掉，
    // 两版编辑乱序到达时才不会把旧版本再推一遍，让对端反而回退
    $pdo = $db->prepare('update `activities` set `updated` = :updated where `id` = :id and `updated` < :updated');
    $pdo->execute([':id' => $activity['id'], ':updated' => $updated]);
    if (!$pdo->rowCount())
        return Club_Inbox_Skip('update is a duplicate or older than what we relayed',
            ['object' => $id, 'updated' => gmdate('Y-m-d\TH:i:s\Z', $updated)]);

    // 先落本地：列表页读的是 announces 里的副本，不跟着改就会和外站显示的不一致。
    // 这一步不依赖 LD 签名，作者身份上面已经确认过了
    $content = strip_tags(is_string($c = $object['content'] ?? '') ? $c : '');
    $summary = is_string($s = $object['summary'] ?? null) ? $s : null;
    $pdo = $db->prepare('update `announces` set `summary` = :summary, `content` = :content where `activity` = :activity');
    $pdo->execute([':activity' => $activity['id'], ':summary' => $summary, ':content' => $content]);

    // 转发是额外的一步。没有 LD 签名的话对端只能从 HTTP 签名验到群组、验不到原作者，
    // 转过去必被丢弃，所以这里静默停在本地，不是失败
    $clubs = json_decode($activity['clubs'], 1) ?: [];
    $vars = ['object' => $id, 'clubs' => $clubs, 'updated' => gmdate('Y-m-d\TH:i:s\Z', $updated)];
    if (!Club_Relay_Allow($jsonld, $input, $id, 'Update'))
        return Club_Log_Event('info', 'update applied locally, not relayed', $vars);
    foreach ($clubs as $club)
        Club_Push_Activity($club, $input, false, false, 'Update-relay');
    Club_Log_Event('info', 'update relayed', $vars);
}

function Club_Follow_Process($jsonld) {
    global $db, $base;
    if (!($club = Club_Object_Name($jsonld['object'] ?? '')))
        return Club_Inbox_Skip('follow target is not a club url',
            ['actor' => $jsonld['actor'], 'object' => Club_Object_Id($jsonld['object'] ?? '')]);
    if ($actor = Club_Get_Actor($club, $jsonld['actor'])) {
        // 对方重发 Follow 是常态，撞唯一键时保留原记录
        $pdo = $db->prepare('insert ignore into `followers`(`cid`,`uid`,`timestamp`) select `cid`, :uid as `uid`, :timestamp as `timestamp` from `clubs` where `name` = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':timestamp' => time()]);
        $pdo = $db->prepare('select f.id from `followers` as f left join `clubs` as `c` on f.cid = c.cid where f.uid = :uid and c.name = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid']]);
        $follow_id = $pdo->fetch(PDO::FETCH_COLUMN, 0);
        $club_url = $base.'/club/'.$club;
        if ($follow_id) {
            Club_Push_Activity($club, [
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
            ], $actor['inbox'], true);
            Club_Log_Event('info', 'follow accepted', ['club' => $club, 'actor' => $jsonld['actor']]);
        // 上面 insert ignore 之后再查不到，只可能是 clubs 那行没了（并发销群）
        } else Club_Inbox_Skip('follow could not be recorded', ['club' => $club, 'actor' => $jsonld['actor']]);
    } else Club_Json_Output(['message' => 'Actor not found'], 0, 400);
}

function Club_Tombstone_Process($jsonld, $input = null) {
    global $db, $base, $public_streams;
    if (!is_array($jsonld['object'] ?? null) || !($object = Club_Object_Id($jsonld['object']['id'] ?? '')))
        return Club_Inbox_Skip('delete without object id', ['actor' => $jsonld['actor']]);
    // Delete 的 id 只拿来去重，不能当准入条件：activity 的 id 在规范里是 SHOULD，
    // GoToSocial 一类实现会省掉，拿它当准入条件的话删嘟会静默失效。
    // 更隐蔽的是有的实现拿被删对象的 URI 当 activity id，而 activities.object 的唯一键
    // 是全表共用的，那样去重查询会命中帖子自己的 Create 记录，同样静默跳过。
    // 统一补成 <对象>#delete 两种都躲开，这也正是 Mastodon 自己用的形式
    $id = Club_Object_Id($jsonld['id'] ?? '');
    if ($id === '' || $id === $object) $id = $object.'#delete';
    // 被限流拦下的帖子没有 Announce 可撤，但可能有回复它的提醒，这一步要先做
    Club_Notice_Delete($object, $jsonld['actor']);
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $id]);
    if ($pdo->fetch(PDO::FETCH_ASSOC))
        Club_Inbox_Skip('delete already processed', ['object' => $object, 'key' => $id]);
    else {
        // join users 是为了限定只有原作者能撤自己的帖，否则谁都能替别人删
        $pdo = $db->prepare('select a.id, a.uid, a.clubs, a.object, a.timestamp from `activities` `a`'.
            ' join `users` `u` on a.uid = u.uid where a.object = :object and u.actor = :actor');
        $pdo->execute([':object' => $object, ':actor' => $jsonld['actor']]);
        if ($activity = $pdo->fetch(PDO::FETCH_ASSOC)) {
            // 撤销记录同样靠唯一键，防止重复投递触发两次 Undo
            $pdo = $db->prepare('insert ignore into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
            $pdo->execute([':uid' => $activity['uid'], ':type' => 'Delete', ':clubs' => $activity['clubs'], ':object' => $id, ':timestamp' => time()]);
            if (!$pdo->rowCount())
                return Club_Inbox_Skip('delete claimed by a concurrent delivery', ['object' => $object]);
            // 作者和「本站转发过」这两条闸门由上面那次 join users 的查询把住，这里只差签名
            $relay = isset($input) && Club_Relay_Allow($jsonld, $input, $object, 'Delete');
            foreach (json_decode($activity['clubs'], 1) ?: [] as $club) {
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
                // Undo 只撤掉群组那条转嘟，跨实例的关注者本地那份原帖会留成孤儿
                //（作者不在他们那儿，谁也不会再来删它）。原始 Delete 一并转出去才能真正清掉。
                // 放在 Undo 之后：不验 LD 签名的实现只认得 Undo，先让它落地
                if ($relay) Club_Push_Activity($club, $input, false, false, 'Delete-relay');
            }
            Club_Log_Event('info', 'announce revoked', ['object' => $object,
                'clubs' => json_decode($activity['clubs'], 1) ?: [], 'delete relayed' => $relay]);
            $pdo = $db->prepare('delete from `announces` where `activity` = :activity');
            $pdo->execute([':activity' => $activity['id']]);
        // 被限流拦下的帖子本来就没转发过，走到这里是正常的；但删嘟没生效时，
        // 这条是唯一能区分「没转发过」和「作者对不上」的线索，不记就只能靠猜
        } else Club_Log_Event('info', 'delete has no announce to revoke',
            ['actor' => $jsonld['actor'], 'object' => $object]);
    }
}

function Club_Undo_Process($jsonld) {
    global $db;
    if (!is_array($jsonld['object'] ?? null))
        return Club_Inbox_Skip('undo without an embedded object', ['actor' => $jsonld['actor']]);
    switch ($type = $jsonld['object']['type'] ?? '') {
        case 'Follow':
            if (!($club = Club_Object_Name($jsonld['object']['object'] ?? '')))
                return Club_Inbox_Skip('unfollow target is not a club url', ['actor' => $jsonld['actor']]);
            $pdo = $db->prepare('delete from `followers` where `cid` in (select cid from `clubs` where `name` = :club) and `uid` in (select uid from `users` where `actor` = :actor)');
            $pdo->execute([':club' => $club, ':actor' => $jsonld['actor']]);
            // 删了 0 行是常态（对端重发 Undo、或从没关注过），但排查掉关注时要能分清
            Club_Log_Event($pdo->rowCount() ? 'info' : 'debug',
                'unfollow, '.$pdo->rowCount().' row(s)', ['club' => $club, 'actor' => $jsonld['actor']]);
            break;
        default: Club_Inbox_Skip('no handler for undo of type', ['type' => $type, 'actor' => $jsonld['actor']]);
    }
}

function Club_Get_OrderedCollection($id, $arr = []) {
    $arr = array_merge([
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $id,
        'type' => 'OrderedCollection',
        'totalItems' => 0
    ], $arr);
    Club_Json_Output($arr, 2);
}

// 游标是「时间戳.自增id」两段，同一秒内有多条也能稳定定位
function Club_Cursor_Parse($cursor) {
    // ?max[]=1 这种数组参数直接挡掉，否则强制转字符串会报警告
    return is_scalar($cursor) && preg_match('/^(\d+)\.(\d+)$/', (string)$cursor, $m)
        ? [(int)$m[1], (int)$m[2]] : false;
}

// AP 里的 id 字段可以直接是字符串，也可以是内嵌对象里的 id；对端还可能塞数组或数字进来
function Club_Object_Id($object) {
    if (is_array($object)) $object = $object['id'] ?? '';
    return is_string($object) ? $object : '';
}

// 从 Follow / Undo 的 object 取群组名
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

// 只放行公网 http(s)：actor、keyId、inbox 都是对端给的，
// 不挡的话伪造一个签名就能让本站去访问 127.0.0.1、云元数据服务之类的内网目标。
// 三态：IP 列表 = 可投的公网地址 / false 一个公网地址都没有或协议不对，该拦 /
// null 解析不出来，什么都没证明。返回的是筛过的地址，调用方必须把它钉给 curl
function Club_Url_Public($url) {
    $parts = parse_url((string)$url);
    if (empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) return false;
    // 域名要先解析成 IP 再判断，否则内网地址套个域名就绕过去了
    $host = trim($parts['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    // 「解析失败」和「对端指向内网」是两回事，混成同一个 false 的话，
    // 本地 DNS 抽一次风就要报一堆 SSRF warning。至于这次失败该不该算对端的账，
    // 这里判不了，交给调用方问 Club_Url_Resolve_Healthy()
    elseif (!($ips = Club_Url_Resolve($host))) return null;
    // 剔掉私网和保留段，剩下的公网地址才交出去。剔掉就等于对 curl 不存在，所以
    // 只拦坏地址不牵连整家：把虚拟机的 fe80:: 发到公网 DNS 的实例并不少见，
    // 一条垃圾 AAAA 不该让一个 A 记录正常的对端整个失联。
    // 这道防线依赖「出网必钉地址」，新增出网路径时必须一起把 $ips 带上，
    // 否则 curl 自己解析一遍就把这里剔掉的地址捡了回去
    $public = [];
    foreach ($ips as $ip)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            $public[] = $ip;
    if (!$public) return false;
    // 剔掉了什么不能不留痕：对端解析结果变成内网是它自己配错还是被劫持，事后要能查
    if (count($public) < count($ips))
        Club_Log_Event('debug', 'dropped non-public addresses', ['host' => $host,
            'kept' => implode(',', $public), 'dropped' => implode(',', array_diff($ips, $public))]);
    // 是不是公网每次都由 IP 现算，不缓存这个结论：它是 IP 的纯函数，而 IP 已经缓存过了。
    // 单独存一份就是第二个时钟，放行的有效期可能超过 IP 的，安全窗口会悄悄漂长
    return $public;
}

// A 查到了就不查 AAAA 的话，对端只要 A 摆个公网地址、AAAA 指 ::1，
// curl 默认优先走 v6 就绕过了检查，所以两种记录都要查，取并集一起校验。
// 缓存只有 hosts 表这一层，几十个 worker 加 fpm 共用。别再往进程内加一层：
// 一次推送几千个对端、每个进程只经手其中一小份，下一条推送绝大多数 host 还是冷的，
// 白占内存还多一个时钟；而查一次主键比查一次 DNS 快两个数量级
function Club_Url_Resolve($host, $ttl = 300, $miss = 60, $stale = 3600) {
    $now = time(); $row = Club_Host_Read($host);
    // 负结果的 TTL 短得多：域名可能刚续费、刚换完 NS，一分钟后就该再试一次
    if ($row && $row['resolved'] > $now - ($row['ips'] === '' ? $miss : $ttl)) {
        // 别的进程刚成功解析过，就是本站 DNS 通着的实据 —— 但成功的时刻是它的，不是此刻
        if ($row['ips'] !== '') Club_Url_Resolve_Healthy($row['resolved']);
        return $row['ips'] === '' ? [] : explode(',', $row['ips']);
    }
    // 几十个进程同时发现同一个 host 过期会一起去查 DNS。抢到 probe 的那个才真去解析，
    // 其余的拿旧值先顶一轮；连旧值都没有的只能自己查
    $stock = $row && $row['ips'] !== '' && $row['resolved'] > $now - $stale ? $row['ips'] : null;
    if (isset($stock) && !Club_Host_Probe($host, $now)) return explode(',', $stock);
    $ips = gethostbynamel($host) ?: [];
    if (function_exists('dns_get_record'))
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rr)
            if (!empty($rr['ipv6'])) $ips[] = $rr['ipv6'];
    if ($ips) {
        Club_Url_Resolve_Healthy(true);
        Club_Host_Resolved($host, $now, implode(',', $ips));
        return $ips;
    }
    // 解析失败时沿用上次的结果，扛过一次性的 SERVFAIL。窗口只给 1 小时：
    // 再长的话，域名真的改指到内网了，我们这边还会拿旧地址放行
    if (isset($stock)) {
        Club_Log_Event('debug', 'dns lookup failed, reusing cached address',
            ['host' => $host, 'age' => $now - $row['resolved'], 'ip' => $stock]);
        return explode(',', $stock);
    }
    // 负结果也要落库。不记的话，一家解析不出来的对端，它名下每一行都要重查一遍，
    // 几千行乘以两次阻塞查询，足够把容器的 UDP conntrack 打满、把好域名也拖成解析失败
    Club_Host_Resolved($host, $now, '');
    Club_Log_Event('debug', 'dns lookup failed, no usable cache', ['host' => $host]);
    return [];
}

// 一次查不到，到底是对端注销了域名、还是本站 DNS 坏了？单看这一次分不出来。
// 但本站 DNS 坏了不会只坏一个 host：最近还成功解析过别的域名，就说明出口是通的，
// 那这次查不到就是对端自己的事，该照常记失败；反之才是我们的问题，不能算在对端头上。
// 进程内状态，worker 重启后先按「不健康」算，宁可晚一点拉黑也别误伤。
// $mark 可以传时间戳：L2 命中时用的是别的进程的成功记录，那是实据，但时刻是它的
function Club_Url_Resolve_Healthy($mark = false, $window = 600) {
    static $last = 0;
    if ($mark !== false) { $last = max($last, $mark === true ? time() : (int)$mark); return true; }
    return $last > time() - $window;
}

function Club_Host_Read($host) {
    global $db;
    $pdo = $db->prepare('select `ips`, `resolved`, `fails`, `since`, `until` from `hosts` where `host` = :host');
    $pdo->execute([':host' => $host]);
    return $pdo->fetch(PDO::FETCH_ASSOC) ?: null;
}

// 抢刷新权。行还不存在时 insert ignore 建出来，插进去的那个自然就是抢到的。
// 用单独一列而不是拿 resolved 兼职：抢到就先推 resolved 的话，这次解析要是失败了，
// 旧 IP 的 stale 窗口会跟着一起续命，上面那道 1 小时的安全边界就守不住了
function Club_Host_Probe($host, $now, $window = 30) {
    global $db;
    $pdo = $db->prepare('update `hosts` set `probe` = :now where `host` = :host and `probe` <= :expire');
    $pdo->execute([':now' => $now, ':host' => $host, ':expire' => $now - $window]);
    if ($pdo->rowCount()) return true;
    $pdo = $db->prepare('insert ignore into `hosts`(`host`, `probe`, `timestamp`) values (:host, :now, :now)');
    $pdo->execute([':host' => $host, ':now' => $now]);
    return (bool)$pdo->rowCount();
}

function Club_Host_Resolved($host, $now, $ips) {
    global $db;
    $pdo = $db->prepare('insert into `hosts`(`host`, `ips`, `resolved`, `probe`, `timestamp`)'.
        ' values (:host, :ips, :now, :now, :now) on duplicate key update'.
        ' `ips` = :ips, `resolved` = :now, `timestamp` = :now');
    return $pdo->execute([':host' => $host, ':ips' => $ips, ':now' => $now]);
}

// 投递失败落到对端这一层：一家挂掉只学一次，它名下几千行在领取时就被跳过，
// 不用每行各付一次 13 秒超时。返回 [熔断到什么时候, 要不要放弃这家, 连续第几次失败]
function Club_Host_Fail($host, $reason) {
    global $db; $now = time(); $row = Club_Host_Read($host);
    $fails = ($row['fails'] ?? 0) + 1;
    $since = ($row['since'] ?? 0) ?: $now;
    $age = $now - $since;
    // 三条阶梯一律读 age，不读 fails。熔断拦不住已经领走的行：一家挂掉的那一刻
    // 几十个 worker 手上各有一行，一轮下来 fails 加的是在途行数而不是 1，
    // 拿它定档位等于让队列积压的多少决定退避快慢。fails 只留着看
    if ($reason == 'blocked') {
        // 指向内网是确定性的，重试不会有不同结果。但内网判定依赖 DNS，
        // 本站解析被投毒或抽一次风就会误伤，所以隔两小时还是这个结论才算数
        $wait = 3600; $drop = $age > 7200;
    } elseif ($reason == 'unresolved') {
        // 换 NS 的传播、域名续费后恢复、DNSSEC 配错修好，都是小时级的事，
        // 前两天密集探测才接得住；之后逐档拉开，一个月还没回来才认定是真没了
        $wait = $age < 172800 ? 300 : ($age < 604800 ? 3600 : 21600);
        $drop = $age > 2592000;
    } else {
        // 对端临时挂掉是常态，这套阶梯本来就是照着它调的
        $wait = $age < 300 ? 60 : ($age < 1800 ? 300 : ($age < 7200 ? 600 : 3600));
        $drop = $age > 604800;
    }
    // 整批行现在共用一个 until，抖动只要算一次。不抖的话一家恢复的那一秒
    // 几十个进程会一起扑上去，对端更容易把我们限流，然后所有行齐步走向放弃
    $until = $now + $wait + mt_rand(0, (int)($wait / 4));
    $pdo = $db->prepare('insert into `hosts`(`host`, `fails`, `since`, `until`, `timestamp`)'.
        ' values (:host, :fails, :since, :until, :now) on duplicate key update'.
        ' `fails` = :fails, `since` = :since, `until` = :until, `timestamp` = :now');
    $pdo->execute([':host' => $host, ':fails' => $fails, ':since' => $since,
        ':until' => $until, ':now' => $now]);
    // 开始挂和放弃是状态变化，中间那些重复失败只记 debug，否则一家大实例挂一天就刷满日志
    Club_Log_Event($fails == 1 || $drop ? 'info' : 'debug', 'host '
        .($drop ? 'given up' : ($fails == 1 ? 'started failing' : 'still failing')).': '.$host,
        ['reason' => $reason, 'fails' => $fails, 'age' => $age, 'wait' => $until - $now]);
    return [$until, $drop, $fails];
}

// 投递成功。$fails 是领取时读到的旧值，为 0 就一个字都不写 ——
// 正常投递没有状态变化可记，而热门对端那一行会被几十个进程反复撞
function Club_Host_Pass($host, $fails) {
    global $db;
    if (!$fails) return false;
    // fails > 0 这个条件是让数据库裁决谁真的清掉了状态：同一批并发成功的行手上都是
    // 领取那一刻的旧计数，各自都会认为是自己恢复的，只有第一条 update 真的改到行
    $pdo = $db->prepare('update `hosts` set `fails` = 0, `since` = 0, `until` = 0, `timestamp` = :now where `host` = :host and `fails` > 0');
    $pdo->execute([':host' => $host, ':now' => time()]);
    if (!$pdo->rowCount()) return false;
    Club_Log_Event('info', 'host recovered: '.$host, ['fails' => $fails]);
    return true;
}

// 放弃一家对端：把它在队列里的所有目标一次性拉黑清干净，而不是等那几千行各自爬到上限。
// 顺序不能反 —— 先扣 tasks 的计数再删队列行，中间挂掉的话计数偏小，
// 那条 tasks 会被维护块提前删掉，剩下的队列行由外键 ON DELETE CASCADE 带走，仍然自洽
function Club_Host_Purge($host) {
    global $db; $now = time();
    $pdo = $db->prepare('insert ignore into `blacklist`(`target`, `create`)'.
        ' select distinct `target`, :create from `queues` where `host` = :host');
    $pdo->execute([':host' => $host, ':create' => $now]);
    $targets = $pdo->rowCount();
    $pdo = $db->prepare('update `tasks` `t` join (select `tid`, count(*) as `n` from `queues`'.
        ' where `host` = :host group by `tid`) `q` on t.tid = q.tid set t.queues = t.queues - q.n');
    $pdo->execute([':host' => $host]);
    $pdo = $db->prepare('delete from `queues` where `host` = :host');
    $pdo->execute([':host' => $host]);
    $queues = $pdo->rowCount();
    // 连续失败到此为止，后面改由 blacklist 每天探活。留着不清的话，
    // 将来探活把它放回来，熔断状态还挂着旧的 until，第一批投递又要白等一轮
    $pdo = $db->prepare('update `hosts` set `fails` = 0, `since` = 0, `until` = 0, `timestamp` = :now where `host` = :host');
    $pdo->execute([':host' => $host, ':now' => $now]);
    // 停止对整个实例投递是个大事件，不记的话事后完全无从追溯
    Club_Log_Event('error', 'host blacklisted: '.$host, ['targets' => $targets, 'queues' => $queues]);
    return $targets;
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

// 模板只负责输出，数据从 $vars 传入，模板里直接读
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
