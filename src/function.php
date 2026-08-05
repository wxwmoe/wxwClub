<?php require_once(__DIR__.'/class/curl.php');

// 跳转自己跟：交给 curl 的话每一跳既过不了内网检查，签名也对不上新 host。跳数与 Mastodon 一致
function ActivityPub_GET($url, $club, $hops = 3) {
    global $curl;
    for ($i = 0; $i <= $hops; $i++) {
        if (!Club_Url_Public($url)) return false;
        $date = gmdate('D, d M Y H:i:s T');
        $result = ActivityPub_CURL($url, $date, [
            'Signature' => ActivityPub_Signature($url, $club, $date)
        ]);
        if ($result === false || !in_array($curl->httpStatusCode, [301, 302, 303, 307, 308])) return $result;
        if (!($location = Club_Header_Get($curl->responseHeaders, 'Location'))) return $result;
        $url = Club_Url_Absolute($location, $url);
    } return false;
}

function ActivityPub_POST($url, $club, $jsonld) {
    if (!Club_Url_Public($url)) return false;
    $date = gmdate('D, d M Y H:i:s T');
	$digest = base64_encode(hash('sha256', $jsonld, 1));
    return ActivityPub_CURL($url, $date, [
        'Signature' => ActivityPub_Signature($url, $club, $date, $digest),
        'Digest' => 'SHA-256='.$digest
    ], $jsonld);
}

function ActivityPub_CURL($url, $date, $head, $data = null) {
    global $ver, $base, $curl, $config; static $last_head = [];
    if (!isset($curl)) $curl = new Curl();
    $curl->setTimeout(10);
    $curl->setConnectTimeout(3);
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

// 低频事件写成按天追加的一个文件：拉黑、建群、销号这类一行就说清楚的事，
// 按事件切成一堆 JSON 文件反而难查。跟 logs/error/ 分开，那边只留 PHP 引擎自己的报错
function Club_Log_Event($level, $message, $context = []) {
    if (!Club_Log_Level($level) || !($path = Club_Log_Dir('event'))) return false;
    if ($context) $message .= ' '.Club_Json_Encode($context);
    // 一条事件一行，消息里的换行要压掉，否则 grep 出来只有半句
    return Club_Log_Put($path.'/'.date('Y-m-d').'.log',
        date('[Y-m-d H:i:s] ').strtoupper($level).' '.preg_replace('/\s+/', ' ', $message)."\n",
        FILE_APPEND);
}

// worker 的输出既要实时给人看，也要留档，两边共用一个入口
function Club_Log_Console($level, $message) {
    if (PHP_SAPI == 'cli') echo date('[Y-m-d H:i:s]').' '.$message, "\n";
    Club_Log_Event($level, $message);
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

// 验签失败时刷新公钥，带冷却时间，防止伪造签名把本站当外连放大器
function Club_Sync_Actor($actor, $cooldown = 3600) {
    global $db; $pdo = $db->prepare('select `refresh` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    $refresh = $pdo->fetch(PDO::FETCH_COLUMN, 0);
    if ($refresh !== false && $refresh > time() - $cooldown) return false;
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

function Club_Push_Activity($club, $activity, $inbox = false, $direct = false) {
    global $db, $config;
    $type = $activity['type'];
    $activity = Club_Json_Encode($activity);
    $name = Club_Log_Name('outbox', [$club, $type]);
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
    } return (bool)$commit;
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
        if ($last && $last > time() - $cooldown) return false;
    } else {
        // 逐条回复对刷屏用户等于反向刷屏，每人每天封顶
        $pdo = $db->prepare('select count(`id`) from `notices` where `uid` = :uid and `timestamp` >= :timestamp');
        $pdo->execute([':uid' => $user['uid'], ':timestamp' => time() - 86400]);
        if ($pdo->fetch(PDO::FETCH_COLUMN, 0) >= ($config['notice']['limit'] ?? 20)) return false;
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

// 群组 inbox 和 shared inbox 共用，避免两处日志走偏
function Club_Log_Inbox($parts, $input, $verify) {
    global $verify_reason, $verify_signed;
    $name = Club_Log_Name('inbox', $parts);
    // 验签没过时正文跟着降到 warning 一起留：只有失败原因没有请求体，排查时等于少了一半
    Club_Log_Write($verify ? 'info' : 'warning', 'inbox', $name.'_input', $input);
    Club_Log_Write('debug', 'inbox', $name.'_server', $_SERVER);
    if (!$verify) Club_Log_Write('warning', 'inbox', $name.'_verify_failed',
        'reason: '.($verify_reason ?? '-')."\n\nsignature: ".($_SERVER['HTTP_SIGNATURE'] ?? '-').
        "\n\ndigest: ".($_SERVER['HTTP_DIGEST'] ?? '-')."\n\nsigned string:\n".($verify_signed ?? '-'), 'txt');
}

// 群组 inbox 和 shared inbox 走同一套流程，$club 为 null 表示 shared inbox
function Club_Inbox_Process($input, $club = null) {
    global $db, $config;
    $jsonld = is_array($jsonld = json_decode($input, 1)) ? $jsonld : [];
    // type 会进日志文件名，不限成纯字母的话对端能用 ../ 穿出 logs 目录
    $type = is_string($t = $jsonld['type'] ?? '') && preg_match('/^[A-Za-z]+$/', $t) ? $t : '';
    $actor = Club_Object_Id($jsonld['actor'] ?? '');
    // actor 必须是外站的绝对地址：本站自己的 activity 不该从 inbox 进来，不是 URL 的也没法验签
    $host = $actor === '' ? '' : (string)parse_url($actor, PHP_URL_HOST);
    if ($type === '' || $host === '' || strcasecmp($host, $config['base']) === 0) {
        // 这三种都进不了验签，不留痕的话冒充本站身份这种明显的攻击特征就完全看不到
        $reason = $type === '' ? 'invalid type: '.substr((string)($jsonld['type'] ?? ''), 0, 100)
            : ($host === '' ? 'actor is not a url: '.substr($actor, 0, 200)
            : 'actor claims local host: '.substr($actor, 0, 200));
        // 基名跟正常请求保持一致的「时间_来源_type」，状态只靠后缀区分
        $name = Club_Log_Name('inbox', [$club ?? 'shared_inbox', $type ?: 'unknown']);
        Club_Log_Write('warning', 'inbox', $name.'_input', $input);
        Club_Log_Write('warning', 'inbox', $name.'_rejected', $reason, 'txt');
        return Club_Json_Output(['message' => 'Request is invalid'], 0, 400);
    }
    $jsonld['actor'] = $actor;

    // 对端注销账号，清掉本地缓存，关注关系靠外键级联删除
    if ($type == 'Delete' && $actor === Club_Object_Id($jsonld['object'] ?? '')) {
        $verify = ActivityPub_Verification($input, false) && ActivityPub_Verify_Actor($actor);
        // 销号会连带级联删掉关注关系，是破坏性最大的一条，成没成都要留痕
        Club_Log_Inbox([$club ?? 'shared_inbox', 'Delete', 'actor'], $input, $verify);
        if ($verify) {
            $pdo = $db->prepare('delete from `users` where `actor` = :actor');
            $pdo->execute([':actor' => $actor]);
            Club_Log_Event('info', 'actor deleted: '.$actor.', '.$pdo->rowCount().' row(s)');
        } return;
    }
    $verify = ActivityPub_Verification($input) && ActivityPub_Verify_Actor($actor);
    Club_Log_Inbox([$club ?? 'shared_inbox', $type], $input, $verify);
    if (($config['node']['inbox-verify'] ?? true) && !$verify) return;
    // 系统群组只负责发私信，不接受关注也不转发投稿
    if (isset($club) && Club_System_Name($club)) return;

    switch ($type) {
        case 'Create': Club_Announce_Process($jsonld); break;
        case 'Follow': Club_Follow_Process($jsonld); break;
        case 'Undo': Club_Undo_Process($jsonld); break;
        case 'Delete':
            // object 可以是内嵌的 Tombstone，也可以直接是被删对象的 id
            if (!isset($jsonld['object']['type']))
                $jsonld['object'] = ['id' => Club_Object_Id($jsonld['object'] ?? ''), 'type' => 'Tombstone'];
            if ($jsonld['object']['type'] == 'Tombstone') Club_Tombstone_Process($jsonld);
            break;
        default: break;
    }
}

function Club_Announce_Process($jsonld) {
    global $db, $base, $config, $public_streams, $club_reason;
    if (!is_array($jsonld['object'] ?? null) || !($object = Club_Object_Id($jsonld['object']['id'] ?? ''))) return;
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
                }
            } else Club_Json_Output(['message' => 'Actor not found'], 0, 400);
        }
    }
}

function Club_Follow_Process($jsonld) {
    global $db, $base;
    if (!($club = Club_Object_Name($jsonld['object'] ?? ''))) return;
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
        }
    } else Club_Json_Output(['message' => 'Actor not found'], 0, 400);
}

function Club_Tombstone_Process($jsonld) {
    global $db, $base, $public_streams;
    if (!($id = Club_Object_Id($jsonld['id'] ?? '')) || !is_array($jsonld['object'] ?? null)
        || !($object = Club_Object_Id($jsonld['object']['id'] ?? ''))) return;
    // 被限流拦下的帖子没有 Announce 可撤，但可能有回复它的提醒，这一步要先做
    Club_Notice_Delete($object, $jsonld['actor']);
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $id]);
    if (!$pdo->fetch(PDO::FETCH_ASSOC)) {
        // join users 是为了限定只有原作者能撤自己的帖，否则谁都能替别人删
        $pdo = $db->prepare('select a.id, a.uid, a.clubs, a.object, a.timestamp from `activities` `a`'.
            ' join `users` `u` on a.uid = u.uid where a.object = :object and u.actor = :actor');
        $pdo->execute([':object' => $object, ':actor' => $jsonld['actor']]);
        if ($activity = $pdo->fetch(PDO::FETCH_ASSOC)) {
            // 撤销记录同样靠唯一键，防止重复投递触发两次 Undo
            $pdo = $db->prepare('insert ignore into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
            $pdo->execute([':uid' => $activity['uid'], ':type' => 'Delete', ':clubs' => $activity['clubs'], ':object' => $id, ':timestamp' => time()]);
            if (!$pdo->rowCount()) return;
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
            }
            $pdo = $db->prepare('delete from `announces` where `activity` = :activity');
            $pdo->execute([':activity' => $activity['id']]);
        }
    }
}

function Club_Undo_Process($jsonld) {
    global $db; if (!is_array($jsonld['object'] ?? null)) return;
    switch ($jsonld['object']['type'] ?? '') {
        case 'Follow':
            if (!($club = Club_Object_Name($jsonld['object']['object'] ?? ''))) break;
            $pdo = $db->prepare('delete from `followers` where `cid` in (select cid from `clubs` where `name` = :club) and `uid` in (select uid from `users` where `actor` = :actor)');
            $pdo->execute([':club' => $club, ':actor' => $jsonld['actor']]); break;
        default: break;
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
// 不挡的话伪造一个签名就能让本站去访问 127.0.0.1、云元数据服务之类的内网目标
function Club_Url_Public($url) {
    $parts = parse_url((string)$url);
    if (empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) return false;
    // 域名要先解析成 IP 再判断，否则内网地址套个域名就绕过去了
    $host = trim($parts['host'], '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : Club_Url_Resolve($host);
    if (!$ips) return false;
    foreach ($ips as $ip)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}

// gethostbynamel 只查 A 记录，IPv6 单栈的对端要靠 AAAA 补上
function Club_Url_Resolve($host) {
    if ($ips = gethostbynamel($host)) return $ips;
    $ips = [];
    if (function_exists('dns_get_record'))
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rr)
            if (!empty($rr['ipv6'])) $ips[] = $rr['ipv6'];
    return $ips;
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
