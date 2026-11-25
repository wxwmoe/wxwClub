<?php

define('DB_VERSION', 8);

function ActivityPub_Collection($id, $arr = []) {
    $arr = array_merge(['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $id, 'type' => 'OrderedCollection', 'totalItems' => 0], $arr);
    // Pleroma requires an explicit item list on empty collections.
    if (!$arr['totalItems'] && !isset($arr['first'])) $arr[$arr['type'] === 'Collection' ? 'items' : 'orderedItems'] = [];
    Club_HTTP_Respond($arr, 'activity+json');
}

function ActivityPub_Digest_Parse($value, &$error = null) {
    static $algos = ['sha-256' => ['sha256', 32], 'sha-512' => ['sha512', 64]];
    $error = '';
    if (!is_string($value) || strlen($value) > 4096) { $error = 'bad length'; return false; }
    $digests = [];
    foreach (explode(',', $value) as $part) {
        if (!preg_match('~^[ \t]*([A-Za-z0-9-]+)[ \t]*=[ \t]*([A-Za-z0-9+/]+={0,2})[ \t]*$~', $part, $matches)) { $error = 'malformed entry'; return false; }
        if (!isset($algos[strtolower($matches[1])])) continue;
        list($algo, $size) = $algos[strtolower($matches[1])];
        if (($raw = base64_decode($matches[2], true)) === false || strlen($raw) !== $size) { $error = 'bad '.$algo.' value'; return false; }
        if (isset($digests[$algo]) && !hash_equals($digests[$algo], $raw)) { $error = 'conflicting '.$algo.' values'; return false; }
        $digests[$algo] = $raw;
    }
    return $digests;
}

function ActivityPub_Fetch($url, $club, $hops = 3, $accept = 'application/activity+json') {
    global $curl, $fetch_retry;
    $fetch_retry = false;
    $https_only = strpos($accept, 'application/jrd+json') !== false;
    for ($i = 0; $i <= $hops; $i++) {
        if ($https_only && stripos($url, 'https://') !== 0) return false;
        if (($public = Club_Url_Public($url)) === false) {
            Club_Log_Event('warning', 'fetch blocked, url is not public', ['url' => $url, 'hop' => $i]);
            return false;
        }
        if ($public === null) {
            Club_Log_Event(Club_Resolver_Healthy() ? 'info' : 'warning', 'fetch skipped, cannot resolve host', ['url' => $url, 'hop' => $i]);
            $fetch_retry = true; return false;
        }
        $date = gmdate('D, d M Y H:i:s T');
        if (!($signature = ActivityPub_Sign($url, $club, $date))) { $fetch_retry = true; return false; }
        $result = ActivityPub_Request($url, $date, ['Signature' => $signature], null, $public, $accept);
        $code = $curl['status'];
        $fetch_retry = !$curl['limit'] && ($curl['curl_error'] || in_array($code, [401, 408, 429]) || ($code >= 500 && $code != 501));
        if ($result === false) return false;
        if (Club_HTTP_Full_Document($code, $curl['headers'])) return $result;
        if (!in_array($code, [301, 302, 303, 307, 308]) || ($location = Club_HTTP_Header($curl['headers'], 'Location')) === '') return false;
        $url = Club_Url_Absolute($location, $url);
    } return false;
}

function ActivityPub_Object_Id($object) {
    if (is_array($object)) $object = $object['id'] ?? '';
    return is_string($object) ? $object : '';
}

function ActivityPub_Object_URI($id, $actor = false) {
    if (!is_string($id) || strlen($id) > ($actor ? 255 : 1020) || !preg_match('/^.{1,255}$/usD', $id)
        || preg_match('~[\p{Z}\p{C}<>"{}|\\\\^`]|%(?![0-9a-f]{2})~iu', $id) || ($actor && preg_match('/[^\x21-\x7e]|#/', $id))) return false;
    if (!preg_match('~^(https?://[^/?#]+)(?:[/?#]|$)~i', $id, $m)) return false;
    return Club_Endpoint_Normalize($m[1].'/') !== false;
}

function ActivityPub_Probe($url, $club, $authorize = null) {
    global $curl, $base;
    $result = ActivityPub_Push($url, $club, Club_Json_Encode(['@context' => 'https://www.w3.org/ns/activitystreams', 'type' => 'Activity', 'actor' => $base.'/club/'.$club]), $authorize);
    if (in_array($result, ['blocked', 'unresolved', 'local-dns', 'local-proxy', 'local-sign', 'lease-lost'], true)) return $result;
    if (empty($curl['status'])) return 'dead';
    $code = $curl['status'];
    return ($code >= 200 && $code < 300) || ($code >= 400 && $code < 500 && !in_array($code, [404, 405, 410])) ? 'alive' : 'dead';
}

function ActivityPub_Push($url, $club, $jsonld, $authorize = null) {
    global $curl;
    if (($public = Club_Url_Public($url)) === false) {
        Club_Log_Event('warning', 'push blocked, url is not public', ['url' => $url, 'club' => $club]);
        return 'blocked';
    }
    if ($public === null) {
        if (Club_Resolver_Deferred(null, $why)) {
            Club_Log_Event('debug', 'push deferred after DNS lookup', ['url' => $url, 'club' => $club, 'reason' => $why]);
            return 'local-dns';
        }
        if (Club_Resolver_Healthy()) {
            Club_Log_Event('info', 'push failed, host does not resolve', ['url' => $url, 'club' => $club]);
            return 'unresolved';
        }
        Club_Log_Event('warning', 'push deferred, local dns looks broken', ['url' => $url, 'club' => $club]);
        return 'local-dns';
    }
    $date = gmdate('D, d M Y H:i:s T');
    $digest = base64_encode(hash('sha256', $jsonld, 1));
    if (!($signature = ActivityPub_Sign($url, $club, $date, $digest))) return 'local-sign';
    $head = ['Signature' => $signature, 'Digest' => 'SHA-256='.$digest];
    if (isset($authorize) && !$authorize()) return 'lease-lost';
    Club_Stat('http_requests');
    ActivityPub_Request($url, $date, $head, $jsonld, $public, 'application/activity+json', $authorize);
    if (!empty($curl['lease_lost'])) return 'lease-lost';
    if ($curl['proxy_error']) return 'local-proxy';
    return ActivityPub_Push_State(isset($curl) ? $curl['status'] : 0);
}

function ActivityPub_Push_State($code) {
    if ($code >= 200 && $code < 300) return 'ok';
    if (in_array($code, [404, 405, 410])) return 'failed';
    if ($code == 501 || ($code >= 400 && $code < 500 && !in_array($code, [401, 408, 429]))) return 'rejected';
    return 'failed';
}

function ActivityPub_Request($url, $date, $head, $data, array $ips, $accept = 'application/activity+json', $reauthorize = null) {
    global $ver, $base, $curl; static $handle = null, $pinned = null;
    if (!$ips) throw new InvalidArgumentException('ActivityPub requests require a verified destination address');
    if (!isset($handle)) $handle = curl_init();
    $options = [CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'wxwClub '.$ver.'; '.$base];
    $headers = ['Accept' => $accept, 'Content-Type' => 'application/activity+json', 'Date' => $date] + $head;
    $curl = Club_HTTP_Request($handle, $url, $headers, $options, $data, $ips, $pinned, 'federation', $reauthorize);
    Club_Log_Write('debug', 'curl', [isset($data) ? 'post' : 'get', $curl['status'], parse_url($url, PHP_URL_HOST)],
        ['status' => $curl['status'], 'body_bytes' => $curl['body_bytes'], 'header_bytes' => $curl['header_bytes'], 'limit' => $curl['limit'], 'error' => $curl['message']]);
    return $curl['error'] ? false : $curl['response'];
}

function ActivityPub_Sign($url, $club, $date, $digest = null) {
    global $db, $base;
    if (!($fields = ActivityPub_Sign_Fields($url))) return false;
    $post = isset($digest);
    $signed_string = "(request-target): ".($post ? 'post' : 'get')." ".$fields['target']."\nhost: ".$fields['authority']."\ndate: $date".($post ? "\ndigest: SHA-256=$digest" : '');
    $pdo = $db->prepare('select `private_key` from `clubs` where `name` = :name');
    $pdo->execute([':name' => $club]);
    if ($pdo = $pdo->fetch(PDO::FETCH_ASSOC)) {
        if (!@openssl_sign($signed_string, $signature, $pdo['private_key'], OPENSSL_ALGO_SHA256)) { Club_Log_Event('error', 'outbound signing failed', ['club' => $club]); return false; }
        return 'keyId="'.$base.'/club/'.$club.'#main-key'.'",algorithm="rsa-sha256",headers="(request-target) host date'.($post ? ' digest' : '').'",signature="'.base64_encode($signature).'"';
    } return false;
}

function ActivityPub_Sign_Fields($url) {
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

function ActivityPub_Signature_Parse($value, &$error = null) {
    static $tchar = '!#$%&\'*+-.^_`|~0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $error = '';
    if (!is_string($value) || $value === '' || strlen($value) > 4096) { $error = 'bad length'; return false; }
    $params = []; $i = 0; $len = strlen($value);
    while (true) {
        $i += strspn($value, " \t", $i);
        if (!($span = strspn($value, $tchar, $i))) { $error = 'bad parameter name'; return false; }
        $name = strtolower(substr($value, $i, $span)); $i += $span;
        $i += strspn($value, " \t", $i);
        if (substr($value, $i, 1) !== '=') { $error = 'missing value for '.$name; return false; }
        $i += 1 + strspn($value, " \t", $i + 1);
        if (substr($value, $i, 1) === '"') {
            $param = ''; $i++;
            while (true) {
                if ($i >= $len) { $error = 'unterminated quoted string'; return false; }
                $char = $value[$i++];
                if ($char === '"') break;
                if ($char === '\\') { if ($i >= $len) { $error = 'unterminated quoted string'; return false; } $char = $value[$i++]; }
                $param .= $char;
            }
        } else {
            if (!($span = strspn($value, $tchar, $i))) { $error = 'missing value for '.$name; return false; }
            $param = substr($value, $i, $span); $i += $span;
        }
        if (isset($params[$name])) { $error = 'duplicate parameter: '.$name; return false; }
        $params[$name] = $param;
        $i += strspn($value, " \t", $i);
        if ($i >= $len) break;
        if ($value[$i] !== ',') { $error = 'bad separator'; return false; }
        $i++;
    }
    $known = ['keyid' => '', 'algorithm' => '', 'headers' => '', 'signature' => '', 'created' => '', 'expires' => ''];
    return array_merge($known, array_intersect_key($params, $known));
}

function ActivityPub_Verify($input = null, $pull = true) {
    global $db, $verify_signed, $verify_actor, $verify_retry, $fetch_retry;
    // GoToSocial uses RSA-SHA256 for hs2019.
    static $algos = ['rsa-sha256' => OPENSSL_ALGO_SHA256, 'hs2019' => OPENSSL_ALGO_SHA256, 'rsa-sha512' => OPENSSL_ALGO_SHA512];
    $verify_retry = false;
    if (empty($_SERVER['HTTP_SIGNATURE'])) return ActivityPub_Verify_Fail('no signature header');
    if (($signature = ActivityPub_Signature_Parse($_SERVER['HTTP_SIGNATURE'], $error)) === false) return ActivityPub_Verify_Fail('malformed signature header: '.$error);
    if ($signature['keyid'] === '' || $signature['signature'] === '' || $signature['headers'] === '') return ActivityPub_Verify_Fail('incomplete signature header');
    $algo = strtolower($signature['algorithm']) ?: 'rsa-sha256';
    if (!isset($algos[$algo])) return ActivityPub_Verify_Fail('unsupported algorithm: '.$algo);
    if (!($raw = base64_decode($signature['signature'], true))) return ActivityPub_Verify_Fail('malformed signature base64');

    $post = strtolower($_SERVER['REQUEST_METHOD']) == 'post';
    $headers = preg_split('/\s+/', strtolower(trim($signature['headers'])), -1, PREG_SPLIT_NO_EMPTY);
    if (!$headers || count(array_unique($headers)) !== count($headers)) return ActivityPub_Verify_Fail('malformed signed header list: '.$signature['headers']);
    if (!in_array('(request-target)', $headers)) return ActivityPub_Verify_Fail('(request-target) not signed');
    $created = in_array('(created)', $headers); $expires = in_array('(expires)', $headers);
    if ($created && (!preg_match('/^\d{1,10}$/D', $signature['created']) || abs(time() - (int)$signature['created']) > 300)) return ActivityPub_Verify_Fail('invalid or stale (created)');
    if ($expires && !preg_match('/^\d{1,10}$/D', $signature['expires'])) return ActivityPub_Verify_Fail('malformed (expires)');
    if (in_array('date', $headers)) {
        if (!ActivityPub_Verify_Date($_SERVER['HTTP_DATE'] ?? '')) return ActivityPub_Verify_Fail('date out of range: '.($_SERVER['HTTP_DATE'] ?? '-').' vs '.gmdate('D, d M Y H:i:s T'));
    } elseif (!$created) return ActivityPub_Verify_Fail('neither date nor (created) signed');
    if ($expires && $created && (int)$signature['expires'] < (int)$signature['created']) return ActivityPub_Verify_Fail('(expires) precedes (created): '.$signature['expires'].' vs '.$signature['created']);
    if ($expires && (int)$signature['expires'] < time()) return ActivityPub_Verify_Fail('signature expired at '.$signature['expires'].' vs '.time());
    if ($post && !in_array('digest', $headers)) return ActivityPub_Verify_Fail('digest not signed');
    if ($post && empty($_SERVER['HTTP_DIGEST'])) return ActivityPub_Verify_Fail('digest header missing');

    $lines = [];
    foreach ($headers as $header) {
        switch ($header) {
            case '(request-target)': $lines[] = $header.': '.strtolower($_SERVER['REQUEST_METHOD']).' '.$_SERVER['REQUEST_URI']; break;
            case '(created)': case '(expires)': $lines[] = $header.': '.$signature[trim($header, '()')]; break;
            default:
                $key = strtoupper(str_replace('-', '_', $header));
                if (!in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) $key = 'HTTP_'.$key;
                if (!array_key_exists($key, $_SERVER)) return ActivityPub_Verify_Fail('signed header missing: '.$header);
                $lines[] = $header.': '.trim((string)$_SERVER[$key], " \t");
        }
    } $verify_signed = implode("\n", $lines);

    $actor = explode('#', $signature['keyid'])[0];
    if (substr($actor, -9) === '/main-key') $actor = substr($actor, 0, -9);
    if (!ActivityPub_Object_URI($actor, true)) return ActivityPub_Verify_Fail('invalid actor key identifier');
    $pdo = $db->prepare('select `public_key` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    if ($public_key = $pdo->fetch(PDO::FETCH_COLUMN, 0)) {
        $key = openssl_pkey_get_public($public_key);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA) ActivityPub_Verify_Fail('public key is not rsa: '.$actor);
        elseif (($result = openssl_verify($verify_signed, $raw, $key, $algos[$algo])) === 1) {
            if ($post && !ActivityPub_Verify_Digest($input)) return false;
            $verify_actor = $actor; return true;
        }
        else ActivityPub_Verify_Fail($result === 0 ? 'signature mismatch' : 'openssl error: '.openssl_error_string());
    } else ActivityPub_Verify_Fail('unknown actor: '.$actor);

    if ($pull && Club_Actor_Sync($actor)) return ActivityPub_Verify($input, false);
    $verify_retry = $pull && !empty($fetch_retry);
    return false;
}

function ActivityPub_Verify_Actor($actor) {
    global $verify_actor;
    if (empty($verify_actor) || $verify_actor !== $actor) return ActivityPub_Verify_Fail('actor mismatch: '.$actor.' vs '.($verify_actor ?: '-'));
    return true;
}

function ActivityPub_Verify_Date($date, $skew = 300, $now = null) {
    if (!is_string($date) || strlen($date) > 35) return false;
    $now = $now ?? time(); $long = false;
    if (preg_match('/^([A-Z][a-z]{2}), (\d\d) ([A-Z][a-z]{2}) (\d{4}) (\d\d:\d\d:\d\d) GMT$/D', $date, $m)) list(, $day, $d, $month, $year, $clock) = $m;
    elseif (preg_match('/^([A-Z][a-z]{5,8}), (\d\d)-([A-Z][a-z]{2})-(\d\d) (\d\d:\d\d:\d\d) GMT$/D', $date, $m)) {
        list(, $day, $d, $month, $yy, $clock) = $m; $long = true;
        $future = (new DateTimeImmutable('@'.$now))->modify('+50 years');
        $year = (int)$future->format('Y') - (int)$future->format('y') + (int)$yy;
    } elseif (preg_match('/^([A-Z][a-z]{2}) ([A-Z][a-z]{2}) ( [1-9]|\d\d) (\d\d:\d\d:\d\d) (\d{4})$/D', $date, $m)) {
        list(, $day, $month, $d, $clock, $year) = $m; $d = sprintf('%02d', (int)$d);
    } else return false;
    $leap = $clock === '23:59:60'; if ($leap) $clock = '23:59:59';
    $text = $d.' '.$month.' '.$year.' '.$clock;
    $value = DateTimeImmutable::createFromFormat('!d M Y H:i:s', $text, new DateTimeZone('UTC'));
    if ($long && $value && $value > $future) {
        $text = $d.' '.$month.' '.($year - 100).' '.$clock;
        $value = DateTimeImmutable::createFromFormat('!d M Y H:i:s', $text, new DateTimeZone('UTC'));
    }
    return $value && $value->format('d M Y H:i:s') === $text && $value->format($long ? 'l' : 'D') === $day && abs($now - ($value->getTimestamp() + (int)$leap)) <= $skew;
}

function ActivityPub_Verify_Digest($input) {
    if (($digests = ActivityPub_Digest_Parse($_SERVER['HTTP_DIGEST'], $error)) === false) return ActivityPub_Verify_Fail('malformed digest header: '.$error);
    if (!$digests) return ActivityPub_Verify_Fail('no supported digest algorithm: '.$_SERVER['HTTP_DIGEST']);
    foreach ($digests as $algo => $digest) if (!hash_equals(hash($algo, (string)$input, 1), $digest)) return ActivityPub_Verify_Fail('digest mismatch on '.$algo.', body length '.strlen((string)$input));
    return true;
}

function ActivityPub_Verify_Fail($reason) {
    global $verify_reason; $verify_reason = $reason; return false;
}

function ActivityPub_WebFinger_Resource($resource, $base, $host) {
    if (preg_match('/^acct:([^@]+)@(.+)$/D', $resource, $matches)) return strcasecmp($matches[2], $host) === 0 ? $matches[1] : null;
    if (preg_match('~^(?i:'.preg_quote($base, '~').')/club/([^/?#]+)$~D', $resource, $matches)) return $matches[1];
    return false;
}

function Club_Actor_Aliased($actor, $document) {
    global $db; static $walking = false;
    if (!($aliases = Club_Actor_Aliases($document))) return;
    if ($walking) return Club_Log_Event('debug', 'actor aliases not followed further', ['actor' => $actor]);
    $walking = true;
    try {
        $follows = $db->prepare('select 1 from `users` where `actor` = :actor and exists (select 1 from `followers` where `followers`.`uid` = `users`.`uid`)');
        foreach ($aliases as $alias) {
            if ($alias === $actor) continue;
            $follows->execute([':actor' => $alias]);
            if (!$follows->fetch(PDO::FETCH_COLUMN, 0)) continue;
            Club_Log_Event('info', 'actor claims an alias that still follows here, confirming the move', ['actor' => $actor, 'alias' => $alias]);
            if (!Club_Actor_Fetch($alias, $aliased, false)) continue;
            if (($moved = ActivityPub_Object_Id($aliased['movedTo'] ?? '')) !== $actor) {
                Club_Log_Event('warning', 'alias move refused, the alias does not point back', ['actor' => $actor, 'alias' => $alias, 'movedTo' => $moved ?: '-']);
                continue;
            }
            Club_Actor_Move($alias, $actor);
        }
    } finally { $walking = false; }
}

function Club_Actor_Aliases($document) {
    $aliases = [];
    foreach (array_slice((array)($document['alsoKnownAs'] ?? []), 0, 10) as $known) if (($alias = ActivityPub_Object_Id($known)) !== '') $aliases[] = $alias;
    return $aliases;
}

function Club_Actor_Cleanup($limit = 500) {
    global $db; $limit = max(1, (int)$limit); $total = 0;
    do {
        $rows = Club_DB_Transaction('actor cleanup', function () use ($db, $limit) {
            $pdo = $db->prepare('delete from `users` where not exists (select 1 from `followers` where `followers`.`uid` = `users`.`uid`)' .
                ' and not exists (select 1 from `activities` where `activities`.`uid` = `users`.`uid`) limit '.$limit);
            $pdo->execute();
            return $pdo->rowCount();
        });
        $total += $rows;
    } while ($rows >= $limit);
    Club_Log_Event('info', 'unused actors cleaned by cli', ['rows' => $total]);
    return $total;
}

function Club_Actor_Confirm($actor, $document) {
    global $db;
    if (($handle = Club_Actor_Handle($document, $actor)) === '') return Club_Log_Event('debug', 'handle not confirmed, no usable candidate', ['actor' => $actor]);
    if (!($self = Club_Actor_Resolve('@'.$handle, $subject))) return Club_Log_Event('debug', 'handle not confirmed, webfinger gave no self link', ['actor' => $actor, 'handle' => $handle]);
    if (!Club_Actor_Same($self, $actor)) {
        Club_Log_Event('warning', 'handle confirmation failed, webfinger does not loop back', ['actor' => $actor, 'handle' => $handle, 'self' => $self]);
        return false;
    }
    if (($confirmed = Club_Actor_Handle(['webfinger' => $subject], $actor)) === '') return false;
    if ($confirmed !== $handle) {
        $self = Club_Actor_Resolve('@'.$confirmed, $canonical);
        if (!$self || !Club_Actor_Same($self, $actor) || Club_Actor_Handle(['webfinger' => $canonical], $actor) !== $confirmed) {
            Club_Log_Event('warning', 'canonical handle did not confirm actor', ['actor' => $actor, 'handle' => $confirmed]); return false;
        }
    }
    return Club_DB_Transaction('handle confirm', function () use ($db, $actor, $confirmed) {
        $pdo = $db->prepare('update `users` set `name` = :name, `webfinger` = :now where `actor` = :actor');
        $pdo->execute([':name' => $confirmed, ':now' => time(), ':actor' => $actor]);
        $pdo = $db->prepare('update `users` set `webfinger` = 0 where `name` = :name and `actor` <> :actor and `webfinger` <> 0');
        $pdo->execute([':name' => $confirmed, ':actor' => $actor]);
        Club_Log_Event('info', 'handle confirmed', ['actor' => $actor, 'handle' => $confirmed, 'invalidated' => $pdo->rowCount()]);
        return $confirmed;
    });
}

function Club_Actor_Confirm_Decide($ask, $fresh, $claim, $name, $webfinger) {
    if ($ask !== null) return (bool)$ask;
    return $fresh || $webfinger === 0 || ($claim !== '' && $claim !== $name);
}

function Club_Actor_Fetch($actor, &$document = null, $confirm = null) {
    global $db, $fetch_retry;
    $document = null; $fetch_retry = false;
    if (!ActivityPub_Object_URI($actor, true)) return false;
    $pdo = $db->prepare('select `uid` from `users` where `actor` = :actor'); $pdo->execute([':actor' => $actor]); $uid = $pdo->fetchColumn();
    if ($uid !== false) { $pdo = $db->prepare('update `users` set `refresh` = :now where `uid` = :uid'); $pdo->execute([':now' => time(), ':uid' => $uid]); }
    if (!($club = Club_Group_Signer())) {
        Club_Log_Event('warning', 'fetch actor skipped, no system club', ['actor' => $actor]);
        $fetch_retry = true; return false;
    }
    $jsonld = json_decode((string)ActivityPub_Fetch($actor, $club), true);
    if (($jsonld['id'] ?? null) !== $actor || !($pem = Club_Actor_Key($jsonld, $actor)) || ($claim = Club_Actor_Handle($jsonld, $actor)) === '' || empty($jsonld['inbox'])) {
        Club_Log_Event('warning', 'fetch actor failed: '.$actor);
        return false;
    }
    if (($inbox = Club_Endpoint_Require($jsonld['inbox'], ['actor' => $actor])) === false) {
        Club_Log_Event('warning', 'fetch actor failed, inbox is not a usable endpoint', ['actor' => $actor, 'inbox' => $jsonld['inbox']]);
        return false;
    }
    $shared = $jsonld['endpoints']['sharedInbox'] ?? null;
    if (!isset($shared) || ($shared = Club_Endpoint_Require($shared, ['actor' => $actor])) === false) $shared = $inbox;
    $document = $jsonld;
    $data = [':name' => $claim, ':inbox' => $inbox, ':public_key' => $pem, ':shared_inbox' => $shared, ':actor' => $actor, ':timestamp' => time()];
    if (!($fresh = $uid === false)) {
        $data[':uid'] = $uid;
        $pdo = $db->prepare('update `users` set `name` = if(`webfinger` = 0, :name, `name`), `inbox` = :inbox,'.
            ' `public_key` = :public_key, `shared_inbox` = :shared_inbox, `refresh` = :timestamp where `uid` = :uid and `actor` = :actor');
    }
    else $pdo = $db->prepare('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`,`timestamp`,`refresh`)'.
        ' values (:name, :actor, :inbox, :public_key, :shared_inbox, :timestamp, :timestamp)');
    try { $pdo->execute($data); }
    catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
    }
    $pdo = $db->prepare('select `uid`,`name`,`inbox`,`shared_inbox`,`webfinger` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    $row = $pdo->fetch(PDO::FETCH_ASSOC);
    if (!$fresh && (!$row || (int)$row['uid'] !== (int)$uid)) return false;
    if ($row && Club_Actor_Confirm_Decide($confirm, $fresh, $claim, $row['name'], (int)$row['webfinger']) && is_string($name = Club_Actor_Confirm($actor, $jsonld))) $row['name'] = $name;
    if ($row) Club_Actor_Aliased($actor, $jsonld);
    return $row;
}

function Club_Actor_Get($actor) {
    global $db, $fetch_retry; $pdo = $db->prepare('select `uid`,`name`,`inbox`,`shared_inbox` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    $row = $pdo->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $inbox = Club_Endpoint_Normalize($row['inbox']);
        $shared = Club_Endpoint_Normalize($row['shared_inbox']);
        if ($inbox !== false && $shared !== false && $inbox === $row['inbox'] && $shared === $row['shared_inbox']) return $row;
        Club_Log_Event('warning', 'cached actor has unusable endpoint, refreshing', ['actor' => $actor, 'inbox' => $row['inbox'], 'shared_inbox' => $row['shared_inbox']]);
    }
    if ($db->inTransaction()) {
        Club_Log_Event('warning', 'actor refresh skipped inside database transaction', ['actor' => $actor]);
        $fetch_retry = true; return false;
    }
    return Club_Actor_Fetch($actor);
}

function Club_Actor_Handle($document, $actor) {
    $handle = is_string($claim = $document['webfinger'] ?? null) ? preg_replace('/^acct:/i', '', trim($claim)) : '';
    if (!preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$/', $handle)) {
        $user = is_string($name = $document['preferredUsername'] ?? null) ? trim($name) : '';
        $host = (string)parse_url(ActivityPub_Object_Id($document['id'] ?? '') ?: $actor, PHP_URL_HOST);
        $handle = $user === '' || $host === '' ? '' : $user.'@'.$host;
    }
    return strlen($handle) <= 100 && preg_match('/^([A-Za-z0-9._-]+)@([A-Za-z0-9.-]+)$/D', $handle, $m) ? $m[1].'@'.strtolower($m[2]) : '';
}

function Club_Actor_Has($actor) {
    global $db; $pdo = $db->prepare('select `uid` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    return (bool)$pdo->fetch(PDO::FETCH_COLUMN, 0);
}

function Club_Actor_Key($document, $actor) {
    $key = $document['publicKey'] ?? null; $pem = $key['publicKeyPem'] ?? null; $id = $key['id'] ?? '';
    if (!is_string($pem) || strlen($pem) > 16384 || ($key['owner'] ?? null) !== $actor || !is_string($id)
        || !($id === $actor.'/main-key' || (strpos($id, $actor.'#') === 0 && strlen($id) > strlen($actor) + 1))) return false;
    $parsed = @openssl_pkey_get_public($pem); $info = $parsed ? openssl_pkey_get_details($parsed) : false;
    return $info && $info['type'] === OPENSSL_KEYTYPE_RSA && $info['bits'] >= 1024 && $info['bits'] <= 8192 ? $info['key'] : false;
}

function Club_Actor_Move($actor, $target) {
    global $db;
    $pdo = $db->prepare('delete from `followers` where `uid` in (select `uid` from `users` where `actor` = :actor)');
    $pdo->execute([':actor' => $actor]);
    Club_Log_Event($pdo->rowCount() ? 'info' : 'debug', 'follower moved away, '.$pdo->rowCount().' row(s) dropped', ['actor' => $actor, 'target' => $target]);
    return $pdo->rowCount();
}

function Club_Actor_Resolve($input, &$subject = null) {
    $input = trim((string)$input); $subject = null;
    if (preg_match('#^https?://#i', $input)) return $input;
    if (!preg_match('/^@?([^@\s]+)@([^@\s]+)$/', $input, $matches)) return false;
    $user = $matches[1]; $host = strtolower(rtrim($matches[2], '.'));
    if (Club_Endpoint_Normalize('https://'.$host.'/') === false) return false;
    if (!($club = Club_Group_Signer())) return false;
    $resource = 'acct:'.$user.'@'.$host;
    $url = 'https://'.$host.'/.well-known/webfinger?resource='.rawurlencode($resource);
    $body = ActivityPub_Fetch($url, $club, 3, 'application/jrd+json, application/json');
    $json = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($json)) {
        Club_Log_Event('warning', 'webfinger failed, response is not json', ['resource' => $resource]);
        return false;
    }
    $subject = is_string($json['subject'] ?? null) ? $json['subject'] : null;
    $fallback = '';
    foreach ((array)($json['links'] ?? []) as $link) {
        if (!is_array($link) || ($link['rel'] ?? '') !== 'self' || !is_string($link['href'] ?? null)) continue;
        $type = strtolower((string)($link['type'] ?? ''));
        if ($type === 'application/activity+json' || strpos($type, 'application/ld+json') === 0) return $link['href'];
        if ($fallback === '') $fallback = $link['href'];
    }
    if ($fallback !== '') return $fallback;
    Club_Log_Event('warning', 'webfinger failed, actor link is missing', ['resource' => $resource]);
    return false;
}

function Club_Actor_Same($left, $right) {
    return $left === $right || (($normalized = Club_Endpoint_Normalize($left)) !== false && $normalized === Club_Endpoint_Normalize($right));
}

function Club_Actor_Sync($actor, $cooldown = 3600) {
    global $db, $fetch_retry; $pdo = $db->prepare('select `refresh` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    $refresh = $pdo->fetch(PDO::FETCH_COLUMN, 0);
    if ($refresh !== false && $refresh > time() - $cooldown) {
        Club_Log_Event('debug', 'actor refresh on cooldown', ['actor' => $actor, 'retry in' => ($refresh + $cooldown - time()).'s']);
        $fetch_retry = true; return false;
    }
    return Club_Actor_Fetch($actor);
}

function Club_Ban_Add($target, $type, $clubs = null, $reason = null, &$effective = null) {
    $effective = Club_DB_Transaction('ban add', function () use ($target, $type, $clubs, $reason) { return Club_Ban_Save($target, $type, $clubs, $reason); });
    $rows = Club_Ban_Detach([$target], $type, $effective);
    Club_Log_Event('info', 'ban added by cli', ['target' => $target, 'type' => $type, 'clubs' => $effective, 'follows dropped' => $rows]);
    return $rows;
}

function Club_Ban_Check($actor, $host) {
    global $db;
    $pdo = $db->prepare('select `target`, `type`, `reason` from `bans` where `clubs` is null and `target` in (:actor, :host)');
    $pdo->execute(Club_Ban_Where($actor, $host));
    return $pdo->fetch(PDO::FETCH_ASSOC);
}

function Club_Ban_Clubs($actor, $host, $clubs) {
    global $db; $banned = [];
    if (!$clubs) return [];
    $pdo = $db->prepare('select `clubs` from `bans` where `clubs` is not null and `target` in (:actor, :host)');
    $pdo->execute(Club_Ban_Where($actor, $host));
    foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $scope)
        foreach (array_intersect($clubs, json_decode($scope, 1) ?: []) as $club) $banned[$club] = 1;
    return $banned;
}

function Club_Ban_Detach($targets, $type, $clubs = null, $dry = false) {
    return Club_Ban_Detach_List([$type => array_fill_keys($targets, $clubs)], $dry);
}

function Club_Ban_Detach_List($bans, $dry) {
    global $db; $total = 0; $cursor = 0;
    if (empty($bans['actor']) && empty($bans['host'])) return 0;
    $pdo = $db->prepare('select `uid`, `actor` from `users` where `uid` > :cursor order by `uid` limit 500');
    do {
        $pdo->execute([':cursor' => $cursor]); $rows = $pdo->fetchAll(PDO::FETCH_ASSOC); $groups = [];
        foreach ($rows as $row) {
            $cursor = (int)$row['uid']; $scope = false;
            foreach (Club_Ban_Where($row['actor'], (string)parse_url($row['actor'], PHP_URL_HOST)) as $param => $target) {
                $rules = $bans[substr($param, 1)] ?? [];
                if (array_key_exists($target, $rules)) $scope = $scope === false ? $rules[$target] : Club_Ban_Scope($scope, $rules[$target]);
            }
            if ($scope !== false) $groups[isset($scope) ? implode(',', $scope) : '*'][] = $cursor;
        }
        foreach ($groups as $scope => $uids) {
            $filter = '';
            if ($scope !== '*') {
                $names = []; foreach (explode(',', $scope) as $club) $names[] = $db->quote($club);
                $filter = ' and `cid` in (select `cid` from `clubs` where `name` in ('.implode(',', $names).'))';
            }
            if ($dry) $total += (int)$db->query('select count(*) from `followers` where `uid` in ('.implode(',', $uids).')'.$filter)->fetchColumn();
            else $total += Club_Ban_Drop($uids, $filter);
        }
    } while (count($rows) === 500);
    return $total;
}

function Club_Ban_Drop($uids, $scope) {
    global $db;
    if (!($uids = array_map('intval', (array)$uids))) return 0;
    return (int)Club_DB_Transaction('ban detach', function () use ($db, $uids, $scope) {
        $list = implode(',', $uids);
        $db->query('select `uid` from `users` where `uid` in ('.$list.') order by `uid` for update')->fetchAll(PDO::FETCH_COLUMN, 0);
        return $db->exec('delete from `followers` where `uid` in ('.$list.')'.$scope);
    });
}

function Club_Ban_Remove($target, $clubs = null) {
    global $db;
    $state = Club_DB_Transaction('ban remove', function () use ($db, $target, $clubs) {
        $pdo = $db->prepare('select `clubs` from `bans` where `target` = :target for update');
        $pdo->execute([':target' => $target]);
        if (($old = $pdo->fetch(PDO::FETCH_COLUMN, 0)) === false) {
            $host = Club_Url_Host((string)parse_url($target, PHP_URL_HOST));
            if ($host === false || $host === $target) return 'absent';
            $pdo = $db->prepare('select 1 from `bans` where `target` = :host');
            $pdo->execute([':host' => $host]);
            return $pdo->fetch(PDO::FETCH_COLUMN, 0) ? 'host-wide' : 'absent';
        }
        if (isset($clubs) && !isset($old)) return 'site-wide';
        $scope = isset($clubs) ? array_values(array_diff(json_decode($old, 1) ?: [], $clubs)) : [];
        if ($scope) {
            $pdo = $db->prepare('update `bans` set `clubs` = :clubs where `target` = :target');
            $pdo->execute([':target' => $target, ':clubs' => Club_Json_Encode($scope)]);
            return 'narrowed';
        }
        $pdo = $db->prepare('delete from `bans` where `target` = :target');
        $pdo->execute([':target' => $target]);
        return 'removed';
    });
    Club_Log_Event($state === 'removed' || $state === 'narrowed' ? 'info' : 'debug', 'ban remove, '.$state, ['target' => $target, 'clubs' => isset($clubs) ? $clubs : 'all']);
    return $state;
}

function Club_Ban_Save($target, $type, $clubs, $reason, $apply = true, $import = false, &$exists = null) {
    global $db;
    if (isset($reason) && (!is_string($reason) || !preg_match('/^[^\x00]{0,255}$/usD', $reason))) throw new InvalidArgumentException('Ban reason must fit 255 Unicode characters');
    $json = isset($clubs) ? Club_Json_Encode($clubs) : null;
    if (isset($json) && strlen($json) > 1024) throw new InvalidArgumentException('Ban scope exceeds 1024 bytes');
    if ($apply) {
        $pdo = $db->prepare('insert into `bans` (`target`,`type`,`clubs`,`reason`,`timestamp`) values (:target,:type,:clubs,:reason,:now) on duplicate key update `target` = `target`');
        $pdo->execute([':target' => $target, ':type' => $type, ':clubs' => $json, ':reason' => $reason, ':now' => time()]); $exists = $pdo->rowCount() === 0;
    }
    $pdo = $db->prepare('select `clubs`, `reason` from `bans` where `target` = :target'.($apply ? ' for update' : '')); $pdo->execute([':target' => $target]);
    $row = $pdo->fetch(PDO::FETCH_ASSOC); if (!$apply) $exists = (bool)$row;
    $scope = $row ? Club_Ban_Scope(isset($row['clubs']) ? json_decode($row['clubs'], true) : null, $clubs) : $clubs;
    if (isset($scope)) sort($scope, SORT_STRING);
    $json = isset($scope) ? Club_Json_Encode($scope) : null;
    if (isset($json) && strlen($json) > 1024) throw new InvalidArgumentException('Merged ban scope exceeds 1024 bytes');
    if ($apply) {
        if (!isset($reason) || ($import && isset($row['reason']))) $reason = $row['reason'];
        $pdo = $db->prepare('update `bans` set `clubs` = :clubs, `reason` = :reason where `target` = :target'); $pdo->execute([':clubs' => $json, ':reason' => $reason === '' ? null : $reason, ':target' => $target]);
    }
    return $scope;
}

function Club_Ban_Scope($old, $new) {
    if (!isset($old) || !isset($new)) return null;
    return array_values(array_unique(array_merge($old, $new)));
}

function Club_Ban_Target($input) {
    $input = trim((string)$input);
    if (preg_match('#^https?://#i', $input)) return ($target = Club_Endpoint_Normalize($input)) === false ? false : ['actor', $target];
    return ($host = Club_Url_Host($input)) === false ? false : ['host', $host];
}

function Club_Ban_Where($actor, $host) {
    return [':actor' => ($target = Club_Endpoint_Normalize($actor)) === false ? '' : $target, ':host' => ($name = Club_Url_Host($host)) === false ? '' : $name];
}

function Club_Group_Actor($club, &$row = null) {
    global $db, $base, $config;
    $pdo = $db->prepare('select `cid`,`name`,`nickname`,`infoname`,`summary`,`avatar`,`banner`,`public_key`,`timestamp` from `clubs` where `name` = :club');
    $pdo->execute([':club' => $club]);
    if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) return false;
    $club = $row['name']; $club_url = $base.'/club/'.$club; $system = Club_Group_System($club);
    $infoname = isset($row['infoname']) ? json_decode($row['infoname'], true) : [];
    $nametag = array_merge($config['default']['infoname'], is_array($infoname) ? $infoname : []);
    $summary = $row['summary'] ?: Club_Template_NameTag($club, $config['default']['summary'], $nametag);
    $nickname = $row['nickname'] ?: Club_Template_NameTag($club, $config['default']['nickname'], $nametag);
    return [
        '@context' => Club_Template_Render('context'),
        'id' => $club_url,
        'type' => $system ? 'Service' : 'Group',
        'following' => $club_url.'/following',
        'followers' => $club_url.'/followers',
        'inbox' => $club_url.'/inbox',
        'outbox' => $club_url.'/outbox',
        'featured' => $club_url.'/collections/featured',
        'featuredTags' => $club_url.'/collections/tags',
        'preferredUsername' => $club,
        'name' => $nickname,
        'summary' => $summary,
        'url' => $club_url,
        'manuallyApprovesFollowers' => $system,
        'discoverable' => false,
        'published' => gmdate('Y-m-d\TH:i:s\Z', $row['timestamp']),
        'devices' => $club_url.'/collections/devices',
        'publicKey' => ['id' => $club_url.'#main-key', 'owner' => $club_url, 'publicKeyPem' => $row['public_key']],
        'tag' => [],
        'attachment' => [],
        'endpoints' => ['sharedInbox' => $base.'/inbox'],
        'icon' => ['type' => 'Image', 'url' => $row['avatar'] ?: $config['default']['avatar']],
        'image' => ['type' => 'Image', 'url' => $row['banner'] ?: $config['default']['banner']]
    ];
}

function Club_Group_Announce($club, $id, $time, $actor, $object) {
    global $base, $public_streams; $url = $base.'/club/'.$club;
    return [
        'id' => $url.'/activity#'.$id.'/announce',
        'type' => 'Announce',
        'actor' => $url,
        'published' => gmdate('Y-m-d\TH:i:s\Z', $time),
        'to' => [$url.'/followers'],
        'cc' => [$actor, $public_streams],
        'object' => $object
    ];
}

function Club_Group_Create($club, $system = false) {
    global $db, $config;
    return Club_DB_Transaction('create club', function () use ($db, $config, $club, $system) {
        if ($db->query("select `value` from `meta` where `name` = 'schema' for update")->fetchColumn() === false) throw new RuntimeException('Missing schema row');
        $pdo = $db->prepare('select `name` from `clubs` where `name` = :club'); $pdo->execute([':club' => $club]);
        if ($existing = $pdo->fetchColumn()) return $existing;
        if (!$system) {
            if (in_array(strtolower($club), $config['club']['suspended-names'])) {
                Club_Log_Event('info', 'club create rejected, suspended name: '.$club);
                return Club_Group_Fail('suspended name');
            }
            if ($limit = $config['club']['create-limit'] ?? 10) {
                $pdo = $db->prepare('select count(cid) from `clubs` where `timestamp` >= :timestamp');
                $pdo->execute([':timestamp' => time() - 3600]);
                if ($pdo->fetch(PDO::FETCH_COLUMN, 0) >= $limit) {
                    Club_Log_Event('warning', 'club create rate limited: '.$club.', '.$limit.'/hour');
                    return Club_Group_Fail('create limited');
                }
            }
        }
        $key = openssl_pkey_new(['digest_alg' => 'sha512', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key || !openssl_pkey_export($key, $priv_key)) {
            Club_Log_Event('error', 'club keygen failed: '.$club.', '.openssl_error_string());
            return Club_Group_Fail('keygen failed');
        }
        $detail = openssl_pkey_get_details($key);
        $pdo = $db->prepare('insert into `clubs`(`name`,`public_key`,`private_key`,`timestamp`) values(:name, :public, :private, :timestamp)');
        try { $pdo->execute([':name' => $club, ':public' => $detail['key'], ':private' => $priv_key, ':timestamp' => time()]); }
        catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        }
        $pdo = $db->prepare('select `name` from `clubs` where `name` = :name');
        $pdo->execute([':name' => $club]);
        return $pdo->fetch(PDO::FETCH_COLUMN, 0);
    });
}

function Club_Group_Ensure($club, $create = true) {
    global $db, $config, $club_reason; $club_reason = null;
    if (!is_string($club) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{1,29}$/D', $club)) return Club_Group_Fail('invalid name');
    $pdo = $db->prepare('select `name` from `clubs` where `name` = :name'); $pdo->execute([':name' => $club]);
    if ($pdo = $pdo->fetch(PDO::FETCH_COLUMN, 0)) return $pdo;
    if (!$create) return Club_Group_Fail('unknown club');
    if (Club_Group_System($club)) return Club_Group_Signer();
    return $config['club']['open-registrations'] ? Club_Group_Create($club) : Club_Group_Fail('registration closed');
}

function Club_Group_Fail($reason) {
    global $club_reason; $club_reason = $reason; return false;
}

function Club_Group_Follow($actor, $club, $follow) {
    global $db;
    $pdo = $db->prepare('select `uid` from `users` where `actor` = :actor'); $pdo->execute([':actor' => $actor]);
    if (!($uid = $pdo->fetch(PDO::FETCH_COLUMN, 0))) return 'user-missing';
    $pdo = $db->prepare('select `cid`, `name` from `clubs` where `name` = :club'); $pdo->execute([':club' => $club]);
    if (!($row = $pdo->fetch(PDO::FETCH_NUM))) return 'club-missing';
    list($cid, $club) = $row;
    if ($follow) {
        $state = Club_DB_Transaction('cli follow', function () use ($db, $actor, $club, $cid, $uid) {
            $pdo = $db->prepare('select `uid` from `users` where `uid` = :uid for update');
            $pdo->execute([':uid' => $uid]);
            if (!$pdo->fetchColumn()) return 'user-missing';
            $host = (string)parse_url($actor, PHP_URL_HOST);
            if (Club_Ban_Check($actor, $host) || Club_Ban_Clubs($actor, $host, [$club])) return 'banned';
            $pdo = $db->prepare('insert ignore into `followers`(`cid`,`uid`,`timestamp`) values (:cid, :uid, :now)');
            $pdo->execute([':cid' => $cid, ':uid' => $uid, ':now' => time()]);
            return $pdo->rowCount() ? 'added' : 'exists';
        });
    } else {
        $pdo = $db->prepare('delete from `followers` where `cid` = :cid and `uid` = :uid');
        $pdo->execute([':cid' => $cid, ':uid' => $uid]);
        $state = $pdo->rowCount() ? 'removed' : 'absent';
    }
    Club_Log_Event($state === 'added' || $state === 'removed' ? 'info' : 'debug', 'cli follow '.$state, ['club' => $club, 'actor' => $actor]);
    return $state;
}

function Club_Group_From_Object($object, $allow_system = false) {
    global $base;
    $id = ActivityPub_Object_Id($object);
    if (!preg_match('~^(?i:'.preg_quote($base, '~').')/club/([a-zA-Z_][a-zA-Z0-9_]{1,29})$~D', $id, $m) || (!$allow_system && Club_Group_System($m[1]))) return false;
    return $m[1];
}

function Club_Group_Page($club, $page) {
    global $db;
    $asc = $page['tail'] || $page['min']; $dir = $asc ? 'asc' : 'desc'; $op = $asc ? '>' : '<';
    $params = [':club' => $club]; $where = '';
    if ($cursor = $page['max'] ?: $page['min']) {
        $where = ' and a.timestamp '.$op.'= :ts and (a.timestamp '.$op.' :ts or a.id '.$op.' :id)';
        $params[':ts'] = $cursor[0]; $params[':id'] = $cursor[1];
    }
    $pdo = $db->prepare('select a.id, a.cid, a.timestamp, a.activity, a.summary, a.content, u.name, u.actor, b.object, b.timestamp as `announced`'.
        ' from `announces` `a` join `clubs` `c` on a.cid = c.cid left join `users` `u` on a.uid = u.uid left join `activities` `b` on a.activity = b.id'.
        ' where c.name = :club'.$where.' order by a.timestamp '.$dir.', a.id '.$dir.' limit 21');
    $pdo->execute($params); $rows = $pdo->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > 20; if ($more) array_pop($rows);
    $back = false;
    if ($cursor && $rows) {
        $edge = $rows[0]; $op = $asc ? '<' : '>';
        $pdo = $db->prepare('select 1 from `announces` where `cid` = :cid and `timestamp` '.$op.'= :ts and (`timestamp` '.$op.' :ts or `id` '.$op.' :id) limit 1');
        $pdo->execute([':cid' => $edge['cid'], ':ts' => $edge['timestamp'], ':id' => $edge['id']]); $back = (bool)$pdo->fetchColumn();
    }
    return [$asc ? array_reverse($rows) : $rows, $asc ? $more : $back, $asc ? $back : $more];
}

function Club_Group_Publish($club) {
    global $db, $public_streams;
    if (!($actor = Club_Group_Actor($club, $row))) return false;
    $pdo = $db->prepare('select count(*) as `followers`, count(distinct `u`.`shared_inbox` collate ascii_bin) as `targets` from `followers` `f` join `users` `u` on `f`.`uid` = `u`.`uid`'.
        ' where `f`.`cid` = :cid and not exists (select 1 from `blacklist` `b` where `b`.`target` = `u`.`shared_inbox` collate ascii_bin)');
    $pdo->execute([':cid' => $row['cid']]); $counts = $pdo->fetch(PDO::FETCH_ASSOC);
    if (!(int)$counts['targets']) return ['followers' => (int)$counts['followers'], 'targets' => 0, 'queued' => false];
    $id = $actor['id'].'#updates/'.time().'-'.bin2hex(random_bytes(4));
    $activity = ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $id, 'type' => 'Update', 'actor' => $actor['id'], 'to' => [$public_streams], 'object' => $actor];
    $queued = Club_Delivery_Queue($club, $activity);
    if ($queued) Club_Log_Event('info', 'club profile update queued', ['club' => $club, 'followers' => (int)$counts['followers'], 'targets' => (int)$counts['targets'], 'activity' => $id]);
    return ['followers' => (int)$counts['followers'], 'targets' => (int)$counts['targets'], 'queued' => (bool)$queued, 'activity' => $id];
}

function Club_Group_Set($club, $field, $value) {
    global $db;
    $fields = ['nickname', 'infoname', 'summary', 'avatar', 'banner'];
    if (!in_array($field, $fields, true)) throw new InvalidArgumentException('Unknown club field: '.$field);
    if (isset($value) && !is_string($value)) throw new InvalidArgumentException('Club field value must be a string or null');
    if ($field === 'nickname' && isset($value) && preg_match_all('/./us', $value, $chars) > 30) throw new InvalidArgumentException('nickname exceeds 30 characters');
    if ($field === 'infoname' && isset($value)) {
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || substr(ltrim($value), 0, 1) !== '{') throw new InvalidArgumentException('infoname must be a JSON object');
        $value = Club_Json_Encode($decoded);
    }
    if (($field === 'avatar' || $field === 'banner') && isset($value)) {
        $parts = parse_url($value);
        if (strlen($value) > 255 || !is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']))
            throw new InvalidArgumentException($field.' must be an HTTP(S) URL no longer than 255 bytes');
    }
    $pdo = $db->prepare('update `clubs` set `'.$field.'` = :value where `name` = :club');
    $pdo->execute([':value' => $value, ':club' => $club]);
    if (!$pdo->rowCount()) {
        $pdo = $db->prepare('select 1 from `clubs` where `name` = :club'); $pdo->execute([':club' => $club]);
        if (!$pdo->fetch(PDO::FETCH_COLUMN, 0)) return false;
    }
    Club_Log_Event('info', 'club profile field changed by cli', ['club' => $club, 'field' => $field, 'cleared' => !isset($value)]);
    return true;
}

function Club_Group_Signer() {
    global $db; $name = Club_Group_System();
    $pdo = $db->prepare('select `name` from `clubs` where `name` = :name');
    $pdo->execute([':name' => $name]);
    return $pdo->fetch(PDO::FETCH_COLUMN, 0) ?: Club_Group_Create($name, true);
}

function Club_Group_System($club = null) {
    global $config; $name = $config['club']['system-name'] ?? 'system';
    return isset($club) ? strtolower($club) === strtolower($name) : $name;
}

function Club_Group_Unannounce($club, $object, $apply) {
    global $db;
    if (!($club = Club_Group_Ensure($club, false)) || Club_Group_System($club)) throw new InvalidArgumentException('Unknown public club');
    if (!ActivityPub_Object_URI($object)) throw new InvalidArgumentException('Invalid object URL');
    return Club_DB_Transaction('club unannounce', function () use ($db, $club, $object, $apply) {
        $result = ['club' => $club, 'object' => $object, 'preview' => !$apply, 'matched' => false, 'withdrawn' => false];
        $pdo = $db->prepare('select a.id, a.timestamp, a.object, u.actor, u.shared_inbox from `activities` `a` join `users` `u` on a.uid = u.uid where a.type = :type and a.object = :object');
        $pdo->execute([':type' => 'Create', ':object' => $object]);
        if (!($activity = $pdo->fetch(PDO::FETCH_ASSOC))) return $result;
        $clubs = Club_Inbox_Announced_Clubs($activity['id'], $apply);
        if (!in_array($club, $clubs, true)) return $result;
        $result['matched'] = true;
        if (!$apply) return $result;
        $undo = Club_Group_Undo($club, $activity, $activity['actor']);
        $identity = ['type' => 'Announce', 'id' => $undo['object']['id'], 'actor' => $undo['actor'], 'object' => $activity['object']];
        $pdo = $db->prepare('delete from `tasks` where `cid` = (select `cid` from `clubs` where `name` = :club)'.
            ' and json_contains(if(json_valid(`jsonld`), `jsonld`, :empty), :identity)');
        $pdo->execute([':club' => $club, ':empty' => '{}', ':identity' => Club_Json_Encode($identity)]);
        if (!Club_Delivery_Queue($club, $undo, $activity['shared_inbox'])) throw new RuntimeException('Could not queue the withdrawal');
        $pdo = $db->prepare('delete from `announces` where `activity` = :id and `cid` = (select `cid` from `clubs` where `name` = :club)');
        $pdo->execute([':id' => $activity['id'], ':club' => $club]);
        $pdo = $db->prepare('update `activities` set `clubs` = :clubs where `id` = :id');
        $pdo->execute([':id' => $activity['id'], ':clubs' => Club_Json_Encode(array_values(array_diff($clubs, [$club])))]);
        $result['withdrawn'] = true;
        return $result;
    });
}

function Club_Group_Undo($club, $activity, $actor) {
    global $base, $public_streams; $url = $base.'/club/'.$club;
    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $url.'/activity#'.$activity['id'].'/undo',
        'type' => 'Undo',
        'actor' => $url,
        'to' => [$public_streams],
        'object' => Club_Group_Announce($club, $activity['id'], $activity['timestamp'], $actor, $activity['object'])
    ];
}

function Club_Inbox_Announced_Clubs($activity, $lock) {
    global $db;
    $pdo = $db->prepare('select (select c.name from `clubs` `c` where c.cid = a.cid) from `announces` `a` where a.activity = :activity order by a.id'.($lock ? ' for update' : ''));
    $pdo->execute([':activity' => $activity]);
    return $pdo->fetchAll(PDO::FETCH_COLUMN);
}

function Club_Inbox_Create($jsonld) {
    global $db, $public_streams, $club_reason;
    if (!is_array($jsonld['object'] ?? null) || !($object = ActivityPub_Object_Id($jsonld['object']['id'] ?? ''))) return Club_Inbox_Skip('create without object id', ['actor' => $jsonld['actor']]);
    $authors = $jsonld['object']['attributedTo'] ?? [];
    if (!is_array($authors) || isset($authors['id'])) $authors = [$authors];
    if (!ActivityPub_Object_URI($object) || !$authors || count($authors) > 32 || array_keys($authors) !== range(0, count($authors) - 1) || !in_array($jsonld['actor'], array_map('ActivityPub_Object_Id', $authors), true)
        || strcasecmp(parse_url($object, PHP_URL_HOST), parse_url($jsonld['actor'], PHP_URL_HOST)) !== 0) throw new Club_Inbox_Rejected('Invalid object or attribution');
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $object]);
    if (!$pdo->fetch(PDO::FETCH_ASSOC)) {
        $lang = Club_I18n_Detect($jsonld['object']);
        $to = array_merge((array)($jsonld['to'] ?? []), (array)($jsonld['cc'] ?? [])); $names = []; $clubs = [];
        if (!in_array($public_streams, $to, true)) return Club_Inbox_Skip('create is not public');
        foreach ($to as $cc) if ($name = Club_Group_From_Object($cc)) $names[strtolower($name)] = $name;
        if (strlen(Club_Json_Encode(array_values($names))) > 255) throw new Club_Inbox_Rejected('Too many recipient groups');
        if (!$names) return Club_Inbox_Skip('create has no local group recipient');
        if (!($actor = Club_Actor_Get($jsonld['actor']))) return Club_Inbox_Unresolved($jsonld['actor']);
        foreach ($names as $name) {
            if ($club = Club_Group_Ensure($name)) $clubs[$club] = 1;
            elseif ($club_reason === 'create limited') Club_Notice_Send($jsonld['actor'], 'create-limit', [], $lang);
        }
        if ($clubs) {
            $clubs = array_keys($clubs);
            sort($clubs, SORT_STRING);
            return Club_DB_Transaction('create inbox', function () use ($db, $clubs, $actor, $jsonld, $object, $lang) {
                $pdo = $db->prepare('select `uid` from `users` where `uid` = :uid for update');
                $pdo->execute([':uid' => $actor['uid']]);
                if (!$pdo->fetchColumn()) return Club_Inbox_Skip('sender removed before submission');
                if (Club_Ban_Check($jsonld['actor'], parse_url($jsonld['actor'], PHP_URL_HOST))) return Club_Inbox_Skip('sender banned before submission');
                $shared_limits = [];
                foreach ($clubs as $club) foreach (Club_Limit_Rules($club) as $rule) if (in_array(strtolower($rule['type']), ['club', 'site'], true)) { $shared_limits[] = $db->quote($club); break; }
                if ($shared_limits) {
                    $ids = $db->query('select `cid` from `clubs` where `name` in ('.implode(',', $shared_limits).') order by `cid`')->fetchAll(PDO::FETCH_COLUMN);
                    $lock = $db->prepare('select `cid` from `clubs` where `cid` = :cid for update');
                    foreach ($ids as $cid) { $lock->execute([':cid' => $cid]); $lock->fetchColumn(); }
                }
                $pdo = $db->prepare('insert ignore into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
                $pdo->execute([':uid' => $actor['uid'], ':type' => 'Create', ':clubs' => Club_Json_Encode($clubs), ':object' => $object, ':timestamp' => ($time = time())]);
                if ($pdo->rowCount() && ($activity_id = $db->lastInsertId())) {
                    $content = strip_tags(is_string($c = $jsonld['object']['content'] ?? '') ? $c : '');
                    $summary = is_string($s = $jsonld['object']['summary'] ?? null) ? $s : null;
                    $announced = [];
                    $banned = Club_Ban_Clubs($jsonld['actor'], parse_url($jsonld['actor'], PHP_URL_HOST), $clubs);
                    foreach ($clubs as $club) {
                        $reject = isset($banned[$club]) ? ['banned-club', ['club' => $club]] : Club_Limit_Check($club, $actor, $content);
                        if ($reject) {
                            Club_Log_Write('info', 'filter', [$club, $reject[0]], $jsonld);
                            Club_Log_Event('info', 'announce filtered, '.$reject[0], ['club' => $club, 'actor' => $jsonld['actor'], 'object' => $object]);
                            if (empty($notified) && ($notified = true)) Club_Notice_Send($jsonld['actor'], $reject[0], $reject[1], $lang, $object);
                            continue;
                        }
                        $announce = Club_Group_Announce($club, $activity_id, $time, $jsonld['actor'], $object);
                        if (!Club_Delivery_Queue($club, ['@context' => 'https://www.w3.org/ns/activitystreams'] + $announce, $actor['shared_inbox'])) continue;
                        $announced[] = $club;
                        $pdo = $db->prepare('insert ignore into `announces`(`cid`,`uid`,`activity`,`summary`,`content`,`timestamp`) select `cid`, :uid as `uid`,'.
                            ' :activity as `activity`, :summary as `summary`, :content as `content`, :timestamp as `timestamp` from `clubs` where `name` = :club');
                        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':activity' => $activity_id, ':summary' => $summary, ':content' => $content, ':timestamp' => $time]);
                    }
                    if ($announced != $clubs) {
                        $pdo = $db->prepare('update `activities` set `clubs` = :clubs where `id` = :id');
                        $pdo->execute([':id' => $activity_id, ':clubs' => Club_Json_Encode($announced)]);
                    }
                    Club_Log_Event($announced ? 'info' : 'warning', 'announce '.($announced ? 'queued' : 'produced nothing'),
                        ['object' => $object, 'clubs' => $announced, 'skipped' => array_values(array_diff($clubs, $announced))]);
                } else Club_Inbox_Skip('create already claimed by a concurrent delivery', ['object' => $object]);
            });
        } else Club_Inbox_Skip('create not addressed to a club, or not public', ['object' => $object, 'clubs' => $clubs ?? [], 'to' => $to]);
    } else Club_Inbox_Skip('create already processed', ['object' => $object]);
}

function Club_Inbox_Delete($jsonld, $input = null) {
    global $db;
    if (!is_array($jsonld['object'] ?? null) || !($object = ActivityPub_Object_Id($jsonld['object']['id'] ?? ''))) return Club_Inbox_Skip('delete without object id', ['actor' => $jsonld['actor']]);
    if (!ActivityPub_Object_URI($object)) throw new Club_Inbox_Rejected('Invalid object identifier');
    $id = 'urn:wxwclub:delete:'.hash('sha256', $jsonld['actor']."\0".$object);
    Club_Notice_Delete($object, $jsonld['actor']);
    $pdo = $db->prepare('select `id` from `activities` where `object` = :object');
    $pdo->execute([':object' => $id]);
    if ($pdo->fetch(PDO::FETCH_ASSOC)) Club_Inbox_Skip('delete already processed', ['object' => $object, 'key' => $id]);
    else {
        $pdo = $db->prepare('select a.id, a.uid, a.object, a.timestamp, u.shared_inbox from `activities` `a` join `users` `u` on a.uid = u.uid where a.object = :object and u.actor = :actor');
        $pdo->execute([':object' => $object, ':actor' => $jsonld['actor']]);
        if ($activity = $pdo->fetch(PDO::FETCH_ASSOC)) {
            return Club_DB_Transaction('delete inbox', function () use ($db, $jsonld, $input, $object, $id, $activity) {
            if (!($clubs = Club_Inbox_Announced_Clubs($activity['id'], true))) return Club_Inbox_Skip('announcement already withdrawn', ['object' => $object]);
            $pdo = $db->prepare('insert ignore into `activities`(`uid`,`type`,`clubs`,`object`,`timestamp`) values(:uid, :type, :clubs, :object, :timestamp)');
            $pdo->execute([':uid' => $activity['uid'], ':type' => 'Delete', ':clubs' => Club_Json_Encode($clubs), ':object' => $id, ':timestamp' => time()]);
            if (!$pdo->rowCount()) return Club_Inbox_Skip('delete claimed by a concurrent delivery', ['object' => $object]);
            $relay = isset($input) && Club_Inbox_Relayable($jsonld, $input, $object, 'Delete');
            foreach ($clubs as $club) Club_Delivery_Queue($club, Club_Group_Undo($club, $activity, $jsonld['actor']));
            if ($relay && $clubs) Club_Delivery_Queue($clubs, $input, false, false, 'Delete-relay', $activity['shared_inbox']);
            Club_Log_Event('info', 'announce revoked', ['object' => $object, 'clubs' => $clubs, 'delete relayed' => $relay]);
            $pdo = $db->prepare('delete from `announces` where `activity` = :activity');
            $pdo->execute([':activity' => $activity['id']]);
            return true;
            });
        } else Club_Log_Event('info', 'delete has no announce to revoke', ['actor' => $jsonld['actor'], 'object' => $object]);
    }
}

function Club_Inbox_Dispatch($input, $club = null) {
    global $db, $config, $verify_reason, $verify_retry;
    $jsonld = is_array($jsonld = json_decode($input, 1)) ? $jsonld : [];
    $wrapped = count($jsonld) === 1 && isset($jsonld[0]) && is_array($jsonld[0]);
    if ($wrapped) $jsonld = $jsonld[0];
    $payload = $wrapped ? null : $input;
    $type = is_string($t = $jsonld['type'] ?? '') && preg_match('/^[A-Za-z]+$/', $t) ? $t : '';
    $actor = ActivityPub_Object_Id($jsonld['actor'] ?? '');
    $host = ActivityPub_Object_URI($actor, true) ? (string)parse_url($actor, PHP_URL_HOST) : '';

    $parts = [$club ?? 'shared_inbox', $type ?: 'unknown'];
    if ($type === 'Delete' && $actor !== '' && $actor === ActivityPub_Object_Id($jsonld['object'] ?? '')) $parts[] = 'actor';
    Club_Log_Ref($name = Club_Log_Name('inbox', $parts));
    if ($wrapped) Club_Log_Event('debug', 'inbox unwrapped json-ld array, relay disabled', ['club' => $club ?? 'shared', 'type' => $type ?: 'unknown']);

    if ($type === '' || $host === '' || strcasecmp($host, parse_url('https://'.$config['base'], PHP_URL_HOST)) === 0) {
        $reason = $type === '' ? 'invalid activity type'
            : ($host === '' ? 'actor is not a url: '.substr($actor, 0, 200) : 'actor claims local host: '.substr($actor, 0, 200));
        Club_Log_Write('warning', 'inbox', $name.'_input', $input);
        Club_Log_Write('warning', 'inbox', $name.'_rejected', $reason, 'txt');
        Club_Log_Event('warning', 'inbox rejected, '.$reason, ['club' => $club ?? 'shared']);
        return Club_Inbox_Reply(400, 'Request is invalid');
    }
    $jsonld['actor'] = $actor;
    Club_Log_Event('debug', 'inbox in, '.$type, ['club' => $club ?? 'shared', 'actor' => $actor, 'object' => ActivityPub_Object_Id($jsonld['object'] ?? '')]);

    if (in_array($type, ['Delete', 'Update'], true) && $actor === ActivityPub_Object_Id($jsonld['object'] ?? '') && !Club_Actor_Has($actor)) {
        Club_Log_Write('debug', 'inbox', $name.'_input', $input);
        return Club_Inbox_Skip('broadcast from actor we never cached', ['type' => $type, 'actor' => $actor]);
    }

    if ($type == 'Delete' && $actor === ActivityPub_Object_Id($jsonld['object'] ?? '')) {
        $verify = ActivityPub_Verify($input, false) && ActivityPub_Verify_Actor($actor);
        Club_Inbox_Log($name, $input, $verify);
        if (!$verify) return Club_Inbox_Reply(403, 'Signature verification failed');
        Club_Blacklist_Sooner($actor);
        Club_DB_Transaction('actor delete inbox', function () use ($db, $jsonld, $payload, $actor) {
            $pdo = $db->prepare('select `uid`, `shared_inbox` from `users` where `actor` = :actor for update');
            $pdo->execute([':actor' => $actor]);
            if (!($user = $pdo->fetch(PDO::FETCH_ASSOC))) return Club_Inbox_Skip('actor delete claimed by a concurrent delivery', ['actor' => $actor]);
            $uid = $user['uid'];
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
            if ($relay_clubs && isset($payload) && Club_Inbox_Relayable($jsonld, $payload, $actor, 'Delete')) {
                $relay_clubs = array_keys($relay_clubs);
                $relayed = Club_Delivery_Queue($relay_clubs, $payload, false, false, 'Delete-relay', $user['shared_inbox']);
                Club_Log_Event($relayed ? 'info' : 'warning', 'actor delete '.($relayed ? 'relayed' : 'not relayed'), ['actor' => $actor, 'clubs' => $relay_clubs]);
            }
            return true;
        });
        return;
    }
    $verify = ActivityPub_Verify($input) && ActivityPub_Verify_Actor($actor);
    Club_Inbox_Log($name, $input, $verify);
    if (($config['node']['inbox-verify'] ?? true) && !$verify) {
        Club_Inbox_Skip('verification failed', ['actor' => $actor, 'reason' => $verify_reason ?? '-']);
        return $verify_retry ? Club_Inbox_Reply(503, 'Unable to verify the signature right now', 60) : Club_Inbox_Reply(403, 'Signature verification failed');
    }
    if (!$verify) Club_Log_Event('warning', 'inbox unverified but accepted, inbox-verify is off', ['actor' => $actor, 'reason' => $verify_reason ?? '-']);

    if ($ban = Club_Ban_Check($actor, $host)) {
        Club_Log_Event('info', 'inbox refused, sender is banned', ['club' => $club ?? 'shared', 'type' => $type, 'actor' => $actor, 'target' => $ban['target']]);
        Club_Notice_Send($actor, 'banned-'.$ban['type'], [], Club_I18n_Detect($jsonld['object'] ?? null), ActivityPub_Object_Id($jsonld['object'] ?? '') ?: null, 0);
        return Club_Inbox_Reply(202, 'Accepted');
    }
    if ($verify) Club_Blacklist_Sooner($actor);
    if ($type === 'Update' && $actor === ActivityPub_Object_Id($jsonld['object'] ?? '')) {
        if (!$verify) return Club_Inbox_Reply(403, 'Signature verification failed');
        if (!Club_Actor_Sync($actor, 60)) return Club_Inbox_Unresolved($actor);
        return Club_Inbox_Skip('actor profile refreshed', ['actor' => $actor]);
    }
    if (isset($club) && Club_Group_System($club)) {
        Club_Inbox_Skip('system club accepts nothing', ['club' => $club, 'type' => $type]);
        return Club_Inbox_Reply(403, 'This actor does not accept this activity');
    }

    if ($type === 'Follow' || ($type === 'Undo' && ($jsonld['object']['type'] ?? '') === 'Follow')) {
        $target = Club_Group_From_Object($type === 'Follow' ? ($jsonld['object'] ?? '') : ($jsonld['object']['object'] ?? ''), true);
        if ($target && Club_Group_System($target)) return Club_Inbox_Reply(403, 'This actor does not accept this activity');
        if ($target === false || (isset($club) && strcasecmp($target, $club) !== 0)) return Club_Inbox_Skip('follow target differs from inbox', ['club' => $club]);
    }
    switch ($type) {
        case 'Create': Club_Inbox_Create($jsonld); break;
        case 'Follow': Club_Inbox_Follow($jsonld); break;
        case 'Undo': Club_Inbox_Undo($jsonld); break;
        case 'Move': Club_Inbox_Move($jsonld); break;
        case 'Update': Club_Inbox_Update($jsonld, $payload); break;
        case 'Delete':
            if (!isset($jsonld['object']['type'])) $jsonld['object'] = ['id' => ActivityPub_Object_Id($jsonld['object'] ?? ''), 'type' => 'Tombstone'];
            if ($jsonld['object']['type'] == 'Tombstone') Club_Inbox_Delete($jsonld, $payload);
            else Club_Inbox_Skip('delete of non-tombstone', ['object type' => $jsonld['object']['type']]);
            break;
        default: Club_Inbox_Skip('no handler for type', ['type' => $type, 'actor' => $actor]);
    }
}

function Club_Inbox_Follow($jsonld) {
    global $db, $base;
    if (!($club = Club_Group_From_Object($jsonld['object'] ?? ''))) return Club_Inbox_Skip('follow target is not a club url',
        ['actor' => $jsonld['actor'], 'object' => ActivityPub_Object_Id($jsonld['object'] ?? '')]);
    $pdo = $db->prepare('select `name` from `clubs` where `name` = :club');
    $pdo->execute([':club' => $club]);
    if (!($club = $pdo->fetch(PDO::FETCH_COLUMN, 0))) return Club_Inbox_Skip('follow target is not a club here', ['actor' => $jsonld['actor'], 'club' => $club]);
    $host = (string)parse_url($jsonld['actor'], PHP_URL_HOST);
    if (Club_Ban_Clubs($jsonld['actor'], $host, [$club])) {
        Club_Notice_Send($jsonld['actor'], 'banned-club', ['club' => $club]);
        return Club_Inbox_Skip('follow refused, banned from this club', ['club' => $club, 'actor' => $jsonld['actor']]);
    }
    if (!($actor = Club_Actor_Get($jsonld['actor']))) return Club_Inbox_Unresolved($jsonld['actor']);
    return Club_DB_Transaction('follow inbox', function () use ($db, $base, $club, $actor, $jsonld, $host) {
        $pdo = $db->prepare('select `uid` from `users` where `uid` = :uid for update');
        $pdo->execute([':uid' => $actor['uid']]);
        if (Club_Ban_Check($jsonld['actor'], $host) || Club_Ban_Clubs($jsonld['actor'], $host, [$club]))
            return Club_Inbox_Skip('follow refused, banned while it was being processed', ['club' => $club, 'actor' => $jsonld['actor']]);
        $pdo = $db->prepare('insert ignore into `followers`(`cid`,`uid`,`timestamp`) select `cid`, :uid as `uid`, :timestamp as `timestamp` from `clubs` where `name` = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid'], ':timestamp' => time()]);
        $pdo = $db->prepare('select f.id from `followers` as f left join `clubs` as `c` on f.cid = c.cid where f.uid = :uid and c.name = :club');
        $pdo->execute([':club' => $club, ':uid' => $actor['uid']]);
        $follow_id = $pdo->fetch(PDO::FETCH_COLUMN, 0);
        $club_url = $base.'/club/'.$club;
        if ($follow_id) {
            $reason = null;
            $queued = Club_Delivery_Queue($club, [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $club_url.'#accepts/follows/'.$follow_id,
                'type' => 'Accept',
                'actor' => $club_url,
                'object' => [
                    'id' => ActivityPub_Object_Id($jsonld['id'] ?? ''),
                    'type' => 'Follow',
                    'actor' => $jsonld['actor'],
                    'object' => $club_url
                ]
            ], $actor['inbox'], true, null, false, $reason);
            if (!$queued) {
                if ($reason === 'blacklisted') throw new Club_Inbox_Deferred('follow Accept target is currently blacklisted');
                throw new Club_Inbox_Rejected('follow Accept enqueue rejected: '.($reason ?: 'unknown'));
            }
            Club_Log_Event('info', 'follow accepted', ['club' => $club, 'actor' => $jsonld['actor']]);
            return true;
        }
        return Club_Inbox_Skip('follow could not be recorded', ['club' => $club, 'actor' => $jsonld['actor']]);
    });
}

function Club_Inbox_Log($name, $input, $verify) {
    global $verify_reason, $verify_signed;
    Club_Log_Write($verify ? 'info' : 'warning', 'inbox', $name.'_input', $input);
    Club_Log_Write('debug', 'inbox', $name.'_server', $_SERVER);
    if (!$verify) Club_Log_Write('warning', 'inbox', $name.'_verify_failed', 'reason: '.($verify_reason ?? '-')."\n\nsignature: ".($_SERVER['HTTP_SIGNATURE'] ?? '-').
        "\n\ndigest: ".($_SERVER['HTTP_DIGEST'] ?? '-')."\n\nsigned string:\n".($verify_signed ?? '-'), 'txt');
}

function Club_Inbox_Move($jsonld) {
    $actor = $jsonld['actor'];
    if ($actor !== ($object = ActivityPub_Object_Id($jsonld['object'] ?? ''))) return Club_Inbox_Skip('move object is not the sender', ['actor' => $actor, 'object' => $object]);
    if (($target = ActivityPub_Object_Id($jsonld['target'] ?? '')) === '' || $target === $actor) return Club_Inbox_Skip('move without a distinct target', ['actor' => $actor, 'target' => $target]);
    Club_Actor_Move($actor, $target);
}

function Club_Inbox_Poll($jsonld, $object) {
    if (isset($jsonld['published'])) return 0;
    if (!in_array('Question', (array)($object['type'] ?? []), true)) return 0;
    if (!is_array($object['oneOf'] ?? null) && !is_array($object['anyOf'] ?? null)) return 0;
    if (!preg_match('#\#updates/([0-9]{1,10})$#', ActivityPub_Object_Id($jsonld['id'] ?? ''), $matches)) return 0;
    return ($revision = (int)$matches[1]) > min(2147483647, time() + 86400) ? 0 : $revision;
}

class Club_Inbox_Deferred extends RuntimeException {}
class Club_Inbox_Rejected extends RuntimeException {}

function Club_Inbox_Receive($club = null, $input = null) {
    $limit = 1024 * 1024;
    $long = (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
    if (!isset($input)) $input = $long ? '' : file_get_contents('php://input', false, null, 0, $limit + 1);
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
        Club_Inbox_Reply(202, 'Accepted');
    }
    catch (Club_Inbox_Deferred $e) {
        Club_Log_Event('warning', 'inbox deferred, outbound enqueue is temporarily blocked', ['error' => $e->getMessage()]);
        Club_Inbox_Reply(503, 'Temporarily unable to enqueue the required response', 300);
    }
    catch (Club_Inbox_Rejected $e) {
        Club_Log_Event('warning', 'inbox activity rejected', ['error' => $e->getMessage()]);
        Club_Inbox_Reply(400, 'Invalid activity');
    }
    catch (PDOException $e) {
        Club_Log_Event('error', 'inbox aborted, database error', ['error' => $e->getMessage()]);
        if ($name = Club_Log_Ref()) Club_Log_Write('error', 'inbox', $name.'_input', $input);
        Club_Inbox_Reply(503, 'Temporarily unable to store the activity', 60);
    }
}

function Club_Inbox_Relayable($jsonld, $input, $object, $type) {
    global $config; $type = strtolower($type);
    if (!is_string($input) || $input === '' || empty($jsonld['signature']['signatureValue'])) {
        Club_Log_Event('info', $type.' not relayed, no ld signature', ['object' => $object]);
        return false;
    }
    if (($size = strlen($input)) > ($limit = ($config['club']['relay-limit'] ?? 512) * 1024)) {
        Club_Log_Event('warning', $type.' not relayed, payload too large', ['object' => $object, 'size' => $size, 'limit' => $limit]);
        return false;
    }
    return true;
}

function Club_Inbox_Reply($status, $message, $retry = 0) {
    static $sent = false;
    if ($sent) return; $sent = true;
    if ($retry) header('Retry-After: '.$retry);
    Club_HTTP_Respond(['message' => $message], 'json', $status);
}

function Club_Inbox_Skip($reason, $context = []) {
    Club_Log_Event('debug', 'inbox skip, '.$reason, $context);
}

function Club_Inbox_Undo($jsonld) {
    global $db;
    if (!is_array($jsonld['object'] ?? null)) return Club_Inbox_Skip('undo without an embedded object', ['actor' => $jsonld['actor']]);
    switch ($type = $jsonld['object']['type'] ?? '') {
        case 'Follow':
            if (!($club = Club_Group_From_Object($jsonld['object']['object'] ?? ''))) return Club_Inbox_Skip('unfollow target is not a club url', ['actor' => $jsonld['actor']]);
            $pdo = $db->prepare('delete from `followers` where `cid` in (select cid from `clubs` where `name` = :club) and `uid` in (select uid from `users` where `actor` = :actor)');
            $pdo->execute([':club' => $club, ':actor' => $jsonld['actor']]);
            Club_Log_Event($pdo->rowCount() ? 'info' : 'debug', 'unfollow, '.$pdo->rowCount().' row(s)', ['club' => $club, 'actor' => $jsonld['actor']]);
            break;
        default: Club_Inbox_Skip('no handler for undo of type', ['type' => $type, 'actor' => $jsonld['actor']]);
    }
}

function Club_Inbox_Unresolved($actor) {
    global $fetch_retry;
    Club_Log_Event('warning', 'inbox '.($fetch_retry ? 'deferred' : 'rejected').', actor document is unusable', ['actor' => $actor]);
    return $fetch_retry ? Club_Inbox_Reply(503, 'Actor is temporarily unavailable', 60) : Club_Inbox_Reply(400, 'Actor document is unusable');
}

function Club_Inbox_Update($jsonld, $input) {
    global $db;
    if (!is_array($object = $jsonld['object'] ?? null) || !($id = ActivityPub_Object_Id($object['id'] ?? ''))) return Club_Inbox_Skip('update without object id', ['actor' => $jsonld['actor']]);
    if (!ActivityPub_Object_URI($id)) throw new Club_Inbox_Rejected('Invalid object identifier');
    $updated = $object['updated'] ?? ''; $edited = 0;
    if (is_string($updated) && preg_match('/^\d{4}-(\d\d)-(\d\d)T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $updated, $m)
        && checkdate((int)$m[1], (int)$m[2], (int)substr($updated, 0, 4))) $edited = strtotime($updated) ?: 0;
    if ($edited > min(2147483647, time() + 86400) || $edited < 0) $edited = 0;
    $pdo = $db->prepare('select a.id, a.updated, u.shared_inbox from `activities` `a` join `users` `u` on a.uid = u.uid where a.object = :object and a.type = :type and u.actor = :actor');
    $pdo->execute([':object' => $id, ':type' => 'Create', ':actor' => $jsonld['actor']]);
    if (!($activity = $pdo->fetch(PDO::FETCH_ASSOC))) return Club_Inbox_Skip('update for a post we never announced, or not from its author', ['actor' => $jsonld['actor'], 'object' => $id]);
    $poll = (bool)($revision = Club_Inbox_Poll($jsonld, $object));
    $edit = $edited > (int)$activity['updated'];
    if (!$poll) {
        if (!$edit) return Club_Inbox_Skip('update is not newer than what we relayed, and not a poll tally', ['object' => $id, 'updated' => gmdate('Y-m-d\TH:i:s\Z', $edited)]);
        $revision = $edited;
    }
    $content = strip_tags(is_string($c = $object['content'] ?? '') ? $c : '');
    $summary = is_string($s = $object['summary'] ?? null) ? $s : null;
    $what = $poll ? 'poll update' : 'update';
    $column = $poll ? 'polled' : 'updated';
    return Club_DB_Transaction($what.' inbox', function () use ($db, $activity, $revision, $content, $summary, $jsonld, $input, $id, $poll, $edit, $edited, $what, $column) {
        if (!($clubs = Club_Inbox_Announced_Clubs($activity['id'], true))) return Club_Inbox_Skip('update after withdrawal', ['object' => $id]);
        $vars = ['object' => $id, 'clubs' => $clubs, 'revision' => gmdate('Y-m-d\TH:i:s\Z', $revision)];
        $pdo = $db->prepare('update `activities` set `'.$column.'` = :revision where `id` = :id and `'.$column.'` < :revision');
        $pdo->execute([':id' => $activity['id'], ':revision' => $revision]);
        if (!$pdo->rowCount()) return Club_Inbox_Skip($what.' is a duplicate or older than what we relayed', ['object' => $id, 'revision' => gmdate('Y-m-d\TH:i:s\Z', $revision)]);
        if ($poll && $edit) {
            $pdo = $db->prepare('update `activities` set `updated` = :edited where `id` = :id and `updated` < :edited');
            $pdo->execute([':id' => $activity['id'], ':edited' => $edited]);
            $edit = (bool)$pdo->rowCount();
        }
        if ($edit) {
            $pdo = $db->prepare('update `announces` set `summary` = :summary, `content` = :content where `activity` = :activity');
            $pdo->execute([':activity' => $activity['id'], ':summary' => $summary, ':content' => $content]);
        }
        if (!Club_Inbox_Relayable($jsonld, $input, $id, $what)) {
            if ($edit) Club_Log_Event('info', 'update applied locally, not relayed', $vars);
            return true;
        }
        $relayed = $clubs && Club_Delivery_Queue($clubs, $input, false, false, $poll ? 'Update-poll' : 'Update-relay', $activity['shared_inbox']);
        Club_Log_Event($relayed ? 'info' : 'warning', $what.($relayed ? ' relayed' : ' not relayed'), $vars);
        return true;
    });
}

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
            case 'site': $where = ' and u.shared_inbox = :site';
                $params[':site'] = $user['shared_inbox']; break;
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

function Club_Limit_Rules($club) {
    global $config;
    $rules = $config['club']['limits'][$club] ?? null;
    if (empty($rules) || !is_array($rules)) return [];
    return isset($rules['type']) ? [$rules] : $rules;
}

function Club_Notice_Delete($object, $actor) {
    global $db;
    $pdo = $db->prepare('select n.id, n.note, u.actor, u.inbox, u.shared_inbox from `notices` `n`'.
        ' join `users` `u` on n.uid = u.uid where n.object = :object and u.actor = :actor and n.note is not null');
    $pdo->execute([':object' => $object, ':actor' => $actor]);
    $notices = $pdo->fetchAll(PDO::FETCH_ASSOC);
    if ($notices && Club_Notice_Endpoint($notices[0]) === false && ($user = Club_Actor_Get($actor))) {
        foreach ($notices as &$notice) {
            $notice['inbox'] = $user['inbox']; $notice['shared_inbox'] = $user['shared_inbox'];
        }
        unset($notice);
    }
    return Club_Notice_Revoke($notices);
}

function Club_Notice_Endpoint($notice) {
    foreach (['inbox', 'shared_inbox'] as $key) {
        $url = $notice[$key] ?? null;
        if (is_string($url) && ($canonical = Club_Endpoint_Normalize($url)) !== false && $canonical === $url) return $url;
    }
    return false;
}

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
        Club_Notice_Revoke($notices); Club_Stat('scheduler_db_ops');
        $count = count($notices);
        if ($count) $cursor = (int)$notices[$count - 1]['id'];
        if ($count >= $limit) return true;
        $cursor = 0; $cleanup = true;
    }
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
    $ready = []; $failed = []; $blocked = 0;
    $blacklist = $db->prepare('select 1 from `blacklist` where `target` = :target');
    foreach ($notices as $notice) {
        $actor = (string)($notice['actor'] ?? '');
        if (($endpoint = Club_Notice_Endpoint($notice)) === false) {
            $failed[] = ['id' => (int)($notice['id'] ?? 0), 'actor' => $actor];
            continue;
        }
        $blacklist->execute([':target' => $endpoint]);
        if ($blacklist->fetchColumn()) { $blocked++; continue; }
        $notice['inbox'] = $endpoint; $ready[] = $notice;
    }
    if ($failed) Club_Log_Event('warning', 'notice revoke deferred, recipient endpoint unavailable', ['count' => count($failed), 'notices' => array_slice($failed, 0, 5)]);
    if ($blocked) Club_Log_Event('info', 'notice revocations deferred for blacklisted inboxes', ['count' => $blocked]);
    if (!$ready || !($club = Club_Group_Signer())) return 0;
    usort($ready, function ($a, $b) { return (int)$a['id'] <=> (int)$b['id']; });
    $club_url = $base.'/club/'.$club;
    return Club_DB_Transaction('notice revoke', function () use ($db, $club, $club_url, $ready) {
        $deleted = 0;
        foreach ($ready as $notice) {
            $pdo = $db->prepare('select `id` from `notices` where `id` = :id and `note` = :note for update');
            $pdo->execute([':id' => $notice['id'], ':note' => $notice['note']]);
            if (!$pdo->fetch(PDO::FETCH_COLUMN, 0)) continue;
            $queued = Club_Delivery_Queue($club, [
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
            $pdo = $db->prepare('update `notices` set `note` = null where `id` = :id');
            $pdo->execute([':id' => $notice['id']]);
            $deleted += $pdo->rowCount();
        }
        return $deleted;
    });
}

function Club_Notice_Send($actor, $type, $vars = [], $lang = null, $reply = null, $cooldown = 3600) {
    global $db, $base, $config;
    if (!($config['notice']['enabled'] ?? true) || empty($actor)) return false;
    if (isset($reply) && !ActivityPub_Object_URI($reply)) return Club_Inbox_Skip('notice reply identifier is unusable', ['actor' => $actor]);
    if (!($club = Club_Group_Signer()) || !($user = Club_Actor_Get($actor))) return false;

    $locale = Club_I18n_Locale($lang);
    $content = '<p><a href="'.$actor.'" class="u-url mention">@'.$user['name'].'</a> '.Club_I18n($type, $locale, $vars).'</p>';
    return Club_DB_Transaction('notice enqueue', function () use ($db, $base, $config, $club, $user, $actor, $type, $reply, $cooldown, $locale, $content) {
        $pdo = $db->prepare('select `uid` from `users` where `uid` = :uid for update');
        $pdo->execute([':uid' => $user['uid']]);
        if (!$pdo->fetchColumn()) return Club_Inbox_Skip('notice recipient removed', ['actor' => $actor]);
        $pdo = $db->prepare('select count(`id`) from `notices` where `uid` = :uid and `timestamp` >= :timestamp');
        $pdo->execute([':uid' => $user['uid'], ':timestamp' => time() - 86400]);
        if (($sent = $pdo->fetch(PDO::FETCH_COLUMN, 0)) >= ($limit = $config['notice']['limit'] ?? 20)) {
            Club_Log_Event('debug', 'notice daily limit reached, '.$type, ['actor' => $actor, 'sent' => $sent, 'limit' => $limit]);
            return false;
        }
        if (!isset($reply) && $cooldown) {
            $pdo = $db->prepare('select max(`timestamp`) from `notices` where `uid` = :uid and `type` = :type');
            $pdo->execute([':uid' => $user['uid'], ':type' => $type]);
            $last = $pdo->fetch(PDO::FETCH_COLUMN, 0);
            if ($last && $last > time() - $cooldown) {
                Club_Log_Event('debug', 'notice on cooldown, '.$type, ['actor' => $actor, 'retry in' => ($last + $cooldown - time()).'s']);
                return false;
            }
        }
        $pdo = $db->prepare('insert into `notices`(`uid`,`type`,`object`,`timestamp`) values (:uid, :type, :object, :timestamp)');
        $pdo->execute([':uid' => $user['uid'], ':type' => $type, ':object' => $reply, ':timestamp' => ($time = time())]);
        if (!($id = $db->lastInsertId())) return false;
        $club_url = $base.'/club/'.$club; $note_url = $club_url.'/notice/'.$id;
        $pdo = $db->prepare('update `notices` set `note` = :note where `id` = :id');
        $pdo->execute([':id' => $id, ':note' => $note_url]);
        $queued = Club_Delivery_Queue($club, [
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

function Club_Task_Category($activity, $direct) {
    if (!is_array($activity)) return 'relay';
    if (($activity['type'] ?? '') === 'Accept') return 'follow';
    if (($activity['type'] ?? '') === 'Update') return 'relay';
    return $direct ? 'notice' : 'announce';
}

function Club_Task_Category_List() {
    return ['follow', 'notice', 'announce', 'relay'];
}

function Club_Task_Cleanup($age = 30, $limit = 200) {
    global $db; $limit = max(1, (int)$limit);
    $pdo = $db->prepare('delete from `tasks` where `timestamp` <= :timestamp and not exists (select 1 from `queues` where queues.tid = tasks.tid) limit '.$limit);
    $pdo->execute([':timestamp' => time() - max(0, (int)$age)]);
    Club_Stat('scheduler_db_ops'); $rows = $pdo->rowCount();
    if ($rows) Club_Monitor_Count('tasks_deleted', $rows);
    return $rows >= $limit;
}

function Club_Task_Create($type, $signer, $jsonld) {
    global $db;
    $pdo = $db->prepare('insert into `tasks`(`cid`,`type`,`jsonld`,`timestamp`) select `cid`, :type as `type`, :jsonld as `jsonld`, :timestamp as `timestamp` from `clubs` where `name` = :club');
    $pdo->execute([':type' => $type, ':club' => $signer, ':jsonld' => $jsonld, ':timestamp' => time()]);
    return $pdo->rowCount() ? $db->lastInsertId() : false;
}

function Club_Queue_Defer($id, $due) {
    global $db;
    $pdo = $db->prepare('update `queues` set `due_at` = :due where `id` = :id');
    $pdo->execute([':id' => $id, ':due' => $due]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

function Club_Queue_Delete($id, $task) {
    global $db;
    $pdo = $db->prepare('delete from `queues` where `id` = :id');
    $result = $pdo->execute([':id' => $id]);
    $deleted = $pdo->rowCount();
    Club_Log_Event('debug', $deleted ? 'queue deleted' : 'queue delete skipped, row not found', ['id' => $id, 'task' => $task]);
    return $result;
}

// Include the author's inbox in the binary lock order.
function Club_Queue_Fanout($task, $category, $clubs, $inbox = false, $origin = false) {
    global $db;
    $cursor = null; $page = 250; $now = time(); $invalid = []; $invalid_count = 0; $names = []; $binds = [];
    $clubs = array_values((array)$clubs);
    foreach ($clubs as $i => $club) { $key = ':club'.$i; $names[] = $key; $binds[$key] = $club; }
    if ($origin !== false) { $skip = ' and u.shared_inbox collate ascii_bin <> convert(:origin using ascii) collate ascii_bin'; $binds[':origin'] = $origin; } else $skip = '';
    do {
        $after = isset($cursor) ? ' and u.shared_inbox collate ascii_bin > convert(:f_cursor using ascii) collate ascii_bin' : '';
        $source = 'select distinct u.shared_inbox collate ascii_bin as `target` from `followers` `f` join `clubs` `c` on f.cid = c.cid'.
            ' join `users` `u` on f.uid = u.uid where c.name in ('.implode(', ', $names).')'.$skip.$after;
        $params = $binds;
        if (isset($cursor)) $params[':f_cursor'] = $cursor;
        if ($inbox !== false) {
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
        if ($valid) {
            $select = []; $insert = [':tid' => $task, ':now' => $now];
            foreach ($valid as $i => $target) {
                $key = ':target'.$i; $insert[$key] = $target;
                $select[] = 'select convert('.$key.' using ascii) collate ascii_bin as `target`';
            }
            $pdo = $db->prepare('insert into `queues`(`tid`,`type`,`target`,`due_at`,`retries`) select :tid, :type, `t`.`target`, :now, 0 from ('.implode(' union all ', $select).') `t`'.
                ' where not exists (select 1 from `blacklist` where `target` = `t`.`target`) order by `t`.`target` collate ascii_bin');
            $insert[':type'] = $category;
            $pdo->execute($insert);
        }
    } while ($count >= $page);
    if ($invalid) Club_Log_Event('warning', 'invalid cached follower endpoints skipped', ['clubs' => $clubs, 'count' => $invalid_count, 'targets' => $invalid]);
    return true;
}

function Club_Queue_Insert($task, $target, $category = 'relay') {
    global $db;
    $pdo = $db->prepare('insert into `queues`(`tid`,`type`,`target`,`due_at`,`retries`) select :tid, :type, :target, :now, 0 from dual where :check not in (select `target` from `blacklist`)');
    $result = $pdo->execute([':tid' => $task, ':type' => $category, ':target' => $target, ':check' => $target, ':now' => time()]);
    if (!$result) return false;
    if (!$pdo->rowCount()) {
        Club_Log_Event('debug', 'push target is blacklisted', ['target' => $target]);
        return null;
    }
    return true;
}

function Club_Queue_Purge($targets, $limit = 500) {
    global $db; $limit = max(1, (int)$limit); $total = 0;
    $targets = array_values(array_unique((array)$targets)); sort($targets, SORT_STRING);
    foreach ($targets as $target) {
        do {
            $rows = 0; $blocked = false;
            try {
                $db->beginTransaction();
                $pdo = $db->prepare('select `url` from `endpoints` where `url` = :target for update'); $pdo->execute([':target' => $target]);
                $pdo = $db->prepare('select `target` from `blacklist` where `target` = :target for update'); $pdo->execute([':target' => $target]);
                $blocked = (bool)$pdo->fetch(PDO::FETCH_COLUMN, 0);
                $pdo = $db->prepare('delete from `queues` where `target` = :target limit '.$limit); $pdo->execute([':target' => $target]);
                $rows = $pdo->rowCount(); $total += $rows;
                if ($rows < $limit && $blocked) {
                    $pdo = $db->prepare('delete from `endpoints` where `url` = :target'); $pdo->execute([':target' => $target]);
                }
                $db->commit();
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollback();
                throw $e;
            }
        } while ($rows >= $limit);
        if (!$blocked) Club_Endpoint_Repair($target, time());
    }
    while (Club_Task_Cleanup(0)) {}
    Club_Log_Event('info', 'queues purged by cli', ['targets' => count($targets), 'rows' => $total]);
    return $total;
}

function Club_Queue_Retry($id, $retries, $due) {
    global $db;
    $pdo = $db->prepare('update `queues` set `due_at` = :due, `retries` = :retries where `id` = :id');
    $pdo->execute([':id' => $id, ':due' => $due, ':retries' => $retries]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

function Club_Lease_Pick($candidates, $token, $attempt, $tries = 3) {
    $count = count($candidates); $start = hexdec(substr($token, 0, 2)) % $count;
    for ($i = 0; $i < min($tries, $count); $i++) {
        $picked = $candidates[($start + $i) % $count];
        if ($attempt($picked)) return $picked;
    }
    return null;
}

function Club_Lease_Token() {
    return bin2hex(random_bytes(16));
}

function Club_Endpoint_Authorize($url, $token, $next, $queue, $category, $lease = 120) {
    global $db; $now = time();
    $pdo = $db->prepare('update `endpoints` set `lease_token` = unhex(:next), `lease_until` = :until where `url` = :url and `lease_token` = unhex(:token) and `retry_at` <= :now'.
        ' and '.Club_Endpoint_Category_Where($category).' and exists (select 1 from `queues` where `id` = :queue and `target` = :url and `type` = :type and `due_at` <= :now)'.
        ' and not exists (select 1 from `blacklist` where `target` = :url)');
    $pdo->execute([':url' => $url, ':token' => $token, ':next' => $next, ':queue' => $queue, ':type' => $category, ':now' => $now, ':until' => $now + $lease]);
    Club_Stat('scheduler_db_ops');
    if ($pdo->rowCount()) return true;
    $pdo = $db->prepare('select (select count(*) from `endpoints` where `url` = :url and `lease_token` = unhex(:token)) as `owned`, (select count(*) from `blacklist`'.
        ' where `target` = :url) as `blocked`, (select count(*) from `queues` where `id` = :queue and `target` = :url and `due_at` <= :now) as `queued`');
    $pdo->execute([':url' => $url, ':token' => $token, ':queue' => $queue, ':now' => $now]);
    $why = $pdo->fetch(PDO::FETCH_ASSOC) ?: [];
    Club_Stat('renew_failed'); Club_Stat('scheduler_db_ops');
    Club_Log_Event('debug', 'pre-HTTP token renewal rejected', ['endpoint' => $url, 'token' => $token,
        'owned' => (int)($why['owned'] ?? 0), 'blacklisted' => (int)($why['blocked'] ?? 0), 'queued' => (int)($why['queued'] ?? 0)]);
    return false;
}

function Club_Endpoint_Backoff($reason, $age, $fails) {
    if ($reason == 'blocked') return [3600, $age > 7200 && $fails >= 3];
    if ($reason == 'unresolved') return [$age < 172800 ? 300 : ($age < 604800 ? 3600 : 21600), $age > 2592000 && $fails >= 7];
    return [$age < 300 ? 60 : ($age < 1800 ? 300 : ($age < 7200 ? 600 : 900)), $age > 604800 && $fails >= 7];
}

function Club_Endpoint_Category_Where($category, $alias = '') {
    $categories = Club_Task_Category_List(); $position = array_search($category, $categories, true);
    if ($position === false) throw new InvalidArgumentException('Unknown delivery category: '.$category);
    $where = $alias.'`'.$category.'_at` is not null and '.$alias.'`'.$category.'_at` <= :now';
    for ($i = 0; $i < $position; $i++) $where .= ' and ('.$alias.'`'.$categories[$i].'_at` is null or '.$alias.'`'.$categories[$i].'_at` > :now)';
    return $where;
}

function Club_Endpoint_Claim($category, $now, $lease = 120) {
    global $db; $start = microtime(true);
    $pdo = $db->prepare('select `url` from `endpoints` where '.Club_Endpoint_Category_Where($category).' and `retry_at` <= :now and `lease_until` <= :now order by `'.$category.'_at` limit 32');
    $pdo->execute([':now' => $now]);
    $candidates = $pdo->fetchAll(PDO::FETCH_COLUMN, 0);
    Club_Stat('endpoint_claim_attempts'); Club_Stat('scheduler_db_ops');
    if (!$candidates) {
        Club_Stat('endpoint_misses');
        Club_Stat_Sample('endpoint_claim_sql_ms', (microtime(true) - $start) * 1000);
        return null;
    }
    $token = Club_Lease_Token();
    $claim = $db->prepare('update `endpoints` set `lease_token` = unhex(:token), `lease_until` = :until'.
        ' where `url` = :url and '.Club_Endpoint_Category_Where($category).' and `retry_at` <= :now and `lease_until` <= :now');
    $url = Club_Lease_Pick($candidates, $token, function ($url) use ($claim, $token, $now, $lease) {
        $claim->execute([':token' => $token, ':until' => $now + $lease, ':url' => $url, ':now' => $now]);
        Club_Stat('scheduler_db_ops');
        return (bool)$claim->rowCount();
    });
    Club_Stat_Sample('endpoint_claim_sql_ms', (microtime(true) - $start) * 1000);
    if (!isset($url)) {
        Club_Stat('endpoint_claim_races');
        Club_Log_Event('debug', 'endpoint claim lost every candidate it tried', ['candidates' => count($candidates)]);
        return null;
    }
    $pdo = $db->prepare('select `url`, `'.$category.'_at` as `category_at`, `next_at`, `retry_at`, `fails` from `endpoints` where `lease_token` = unhex(:token)');
    $pdo->execute([':token' => $token]); Club_Stat('scheduler_db_ops');
    if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) {
        Club_Log_Event('warning', 'endpoint claim lost before read', ['token' => $token]);
        return null;
    }
    $row['token'] = $token;
    $row['category'] = $category;
    Club_Stat('endpoint_claims');
    Club_Stat_Max('endpoint_schedule_lag_s', max(0, $now - (int)$row['category_at']));
    Club_Log_Event('debug', 'endpoint claimed', ['endpoint' => $row['url'], 'category' => $category, 'token' => $token, 'lag' => max(0, $now - (int)$row['category_at'])]);
    return $row;
}

function Club_Endpoint_Decide($result, $fails, $since, $retry_at, $retries, $now, $jitter = null) {
    $fails = (int)$fails; $since = (int)$since; $retry_at = (int)$retry_at; $retries = (int)$retries;
    $plan = ['queue' => 'keep', 'due_at' => null, 'retries' => $retries, 'fails' => $fails, 'fail_since' => $since,
        'retry_at' => $retry_at, 'endpoint' => false, 'blacklist' => false, 'state' => '', 'age' => 0];
    switch ($result) {
        case 'ok': case 'rejected':
            $plan['queue'] = 'delete';
            if ($fails || $since || $retry_at) {
                $plan['fails'] = 0; $plan['fail_since'] = 0; $plan['retry_at'] = 0;
                $plan['age'] = $since ? $now - $since : 0; $plan['endpoint'] = true; $plan['state'] = 'recovered';
            } return $plan;
        case 'dropped': $plan['queue'] = 'delete'; return $plan;
        case 'local-dns': case 'local-proxy': case 'local-sign': $plan['queue'] = 'defer'; $plan['due_at'] = $now + 300; $plan['state'] = 'deferred';
            if ($retry_at < $now + 300) { $plan['retry_at'] = $now + 300; $plan['endpoint'] = true; }
            return $plan;
    }
    $begin = $since === 0; if ($begin) $since = $now;
    $plan['fails'] = ++$fails; $plan['fail_since'] = $since; $plan['age'] = $age = $now - $since;
    list($wait, $drop) = Club_Endpoint_Backoff($result, $age, $fails);
    $plan['retry_at'] = $now + $wait + (isset($jitter) ? (int)$jitter : mt_rand(0, (int)($wait / 4)));
    $plan['endpoint'] = true;
    if ($begin) $plan['retries'] = $retries + 1;
    if ($drop) { $plan['blacklist'] = true; $plan['state'] = 'blacklisted'; }
    elseif ($plan['retries'] >= 8) { $plan['queue'] = 'delete'; $plan['state'] = 'exhausted'; }
    else { $plan['queue'] = 'retry'; $plan['due_at'] = $plan['retry_at']; $plan['state'] = $begin ? 'failing' : 'still-failing'; }
    return $plan;
}

function Club_Endpoint_Desired($url, $retry_at) {
    $schedule = Club_Endpoint_Schedule($url, $retry_at);
    return $schedule['next_at'];
}

function Club_Endpoint_Drifted($schedule, $row) {
    foreach (array_merge(['next_at'], Club_Task_Category_List()) as $key) {
        $column = substr($key, -3) === '_at' ? $key : $key.'_at';
        if ($schedule[$column] !== (isset($row[$column]) ? (int)$row[$column] : null)) return true;
    }
    return ((int)$row['idle_since'] > 0) !== !isset($schedule['next_at']);
}

function Club_Endpoint_Normalize($url) {
    if (!is_string($url) || $url === '') return false;
    if (preg_match('/[^\x21-\x7e]/', $url) || strpos($url, '#') !== false || !($parts = parse_url($url))) return false;
    if (!isset($parts['scheme'], $parts['host'])) return false;
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    if (($scheme = strtolower($parts['scheme'])) !== 'http' && $scheme !== 'https') return false;
    $host = $parts['host'];
    if (substr($host, 0, 1) === '[') {
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
    // PHP 7.3 drops an empty query in parse_url().
    if (($query = strpos($url, '?')) !== false) $canonical .= substr($url, $query);
    return strlen($canonical) > 255 ? false : $canonical;
}

function Club_Endpoint_Prune($url, $now, $idle = 604800) {
    global $db; $pruned = false; $idled = false; $before = $now - $idle;
    $pdo = $db->prepare('select `e`.`lease_until` as `lease`, `e`.`idle_since` as `idle`, `e`.`retry_at` as `retry`, (select count(*) from `queues`'.
        ' where `target` = :url) as `queued`, (select count(*) from `blacklist` where `target` = :url) as `blocked` from `endpoints` as `e` where `e`.`url` = :url');
    $pdo->execute([':url' => $url]); Club_Stat('scheduler_db_ops');
    $peek = $pdo->fetch(PDO::FETCH_ASSOC) ?: [];
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
                $pdo = $db->prepare('update `endpoints` set `next_at` = null, `follow_at` = null, `notice_at` = null, `announce_at` = null, `relay_at` = null, `idle_since` = :now'.
                    ' where `url` = :url and `lease_until` <= :now and `idle_since` = 0');
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
    if ($pruned) Club_Log_Event('debug', 'idle endpoint removed', ['endpoint' => $url, 'idle' => $now - (int)$row['idle_since']]);
    elseif ($idled) Club_Log_Event('warning', 'endpoint idle clock repaired', ['endpoint' => $url]);
    return $pruned ? 'pruned' : ($idled ? 'idled' : false);
}

function Club_Endpoint_Prune_Decide($lease, $idle, $retry, $queued, $blocked, $now, $before) {
    if (!isset($lease) || (int)$lease > $now || (int)$queued || (int)$blocked) return false;
    if (!(int)$idle) return 'idle';
    return (int)$idle <= $before && (int)$retry <= $now ? 'prune' : false;
}

function Club_Endpoint_Queue($url, $token, $category, $now) {
    global $db;
    $pdo = $db->prepare('select `q`.`id`, `q`.`tid`, `q`.`target`, `q`.`due_at`, `q`.`retries`, `t`.`type`, `t`.`jsonld`, `c`.`name` as `club` from `endpoints` as `e`'.
        ' join `queues` as `q` on q.target = e.url left join `tasks` as `t` on q.tid = t.tid left join `clubs` as `c` on t.cid = c.cid where `e`.`url` = :url and `e`.`lease_token` = unhex(:token)'.
        ' and `e`.`lease_until` > :now and `e`.`retry_at` <= :now and '.Club_Endpoint_Category_Where($category, '`e`.').' and `q`.`type` = :type'.
        ' and `q`.`due_at` <= :now and not exists (select 1 from `blacklist` where `target` = :url) order by `q`.`due_at`, `q`.`id` limit 1');
    $pdo->execute([':url' => $url, ':token' => $token, ':type' => $category, ':now' => $now]); Club_Stat('scheduler_db_ops');
    if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) return null;
    Club_Stat_Max('queue_due_lag_s', max(0, $now - (int)$row['due_at']));
    return $row;
}

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

function Club_Endpoint_Repair($url, $now) {
    global $db; $repaired = false; $schedule = ['next_at' => null, 'follow_at' => null, 'notice_at' => null, 'announce_at' => null, 'relay_at' => null];
    $pdo = $db->prepare('select `next_at`, `follow_at`, `notice_at`, `announce_at`, `relay_at`, `retry_at`, `idle_since`, `lease_until` from `endpoints` where `url` = :url');
    $pdo->execute([':url' => $url]); Club_Stat('scheduler_db_ops');
    if (($peek = $pdo->fetch(PDO::FETCH_ASSOC)) !== false) {
        if ((int)$peek['lease_until'] > $now) return false;
        if (!Club_Endpoint_Drifted(Club_Endpoint_Schedule($url, (int)$peek['retry_at']), $peek)) return false;
    }
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `next_at`, `follow_at`, `notice_at`, `announce_at`, `relay_at`, `retry_at`, `idle_since`, `lease_until` from `endpoints` where `url` = :url for update');
        $pdo->execute([':url' => $url]);
        if (($row = $pdo->fetch(PDO::FETCH_ASSOC)) === false) {
            $pdo = $db->prepare('insert ignore into `endpoints`(`url`, `next_at`, `idle_since`) values (:url, null, :now)');
            $pdo->execute([':url' => $url, ':now' => $now]);
            if ($pdo->rowCount()) Club_Log_Event('warning', 'endpoint control row rebuilt with healthy defaults', ['endpoint' => $url]);
            $pdo = $db->prepare('select `next_at`, `follow_at`, `notice_at`, `announce_at`, `relay_at`, `retry_at`, `idle_since`, `lease_until` from `endpoints` where `url` = :url for update');
            $pdo->execute([':url' => $url]);
            $row = $pdo->fetch(PDO::FETCH_ASSOC);
        }
        if ($row !== false && (int)$row['lease_until'] <= $now) {
            $schedule = Club_Endpoint_Schedule($url, (int)$row['retry_at']);
            if (Club_Endpoint_Drifted($schedule, $row)) {
                $pdo = $db->prepare('update `endpoints` set `next_at` = :next, `follow_at` = :follow, `notice_at` = :notice, `announce_at` = :announce, `relay_at` = :relay,'.
                    ' `idle_since` = if(:next is null, if(`idle_since` > 0, `idle_since`, :now), 0) where `url` = :url and `lease_until` <= :now');
                $pdo->execute([':url' => $url, ':next' => $schedule['next_at'], ':follow' => $schedule['follow_at'], ':notice' => $schedule['notice_at'],
                    ':announce' => $schedule['announce_at'], ':relay' => $schedule['relay_at'], ':now' => $now]);
                $repaired = (bool)$pdo->rowCount();
            }
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Stat('scheduler_db_ops', 2);
    if ($repaired) Club_Log_Event('warning', 'endpoint schedule repaired', ['endpoint' => $url, 'next_at' => $schedule['next_at']]);
    return $repaired;
}

function Club_Endpoint_Require($url, $context = []) {
    if (($canonical = Club_Endpoint_Normalize($url)) !== false) return $canonical;
    Club_Log_Event('warning', 'endpoint rejected, url cannot be normalized', array_merge(['url' => is_string($url) ? substr($url, 0, 255) : null], $context));
    return false;
}

function Club_Endpoint_Reschedule($url, $token, $retry_at) {
    global $db; $now = time();
    $schedule = Club_Endpoint_Schedule($url, $retry_at);
    $pdo = $db->prepare('update `endpoints` set `next_at` = :next, `follow_at` = :follow, `notice_at` = :notice, `announce_at` = :announce, `relay_at` = :relay,'.
        ' `lease_token` = null, `lease_until` = 0, `idle_since` = if(:next is null,'.
        ' if(`idle_since` > 0, `idle_since`, :now), 0) where `url` = :url and `lease_token` = unhex(:token)');
    $pdo->execute([':url' => $url, ':token' => $token, ':next' => $schedule['next_at'], ':follow' => $schedule['follow_at'], ':notice' => $schedule['notice_at'],
        ':announce' => $schedule['announce_at'], ':relay' => $schedule['relay_at'], ':now' => $now]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

function Club_Endpoint_Result($url, $token, $task, $result) {
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
        // Delivery and cleanup lock the endpoint, blacklist entry, then queue row.
        $pdo = $db->prepare('select `target` from `blacklist` where `target` = :url for update');
        $pdo->execute([':url' => $url]); Club_Stat('scheduler_db_ops');
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
        Club_Endpoint_Reschedule($url, $token, $plan['retry_at']);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Stat('endpoint_done');
    $state = $plan['state'];
    if ($state == 'recovered') Club_Log_Event('info', 'endpoint recovered: '.$url, ['fails' => $plan['fails'], 'age' => $plan['age'], 'via' => $result == 'ok' ? 'delivery' : 'transport']);
    elseif ($state == 'failing') Club_Log_Event('info', 'endpoint started failing: '.$url, ['reason' => $result, 'wait' => $plan['retry_at'] - $now]);
    elseif ($state == 'blacklisted') Club_Log_Event('info', 'endpoint blacklisted: '.$url, ['reason' => $result, 'fails' => $plan['fails'], 'age' => $plan['age']]);
    if ($result == 'ok') Club_Log_Event('debug', 'push delivered', ['club' => $task['club'], 'target' => $url, 'retries' => (int)$task['retries']]);
    elseif ($result == 'rejected') Club_Log_Event('info', 'push dropped, target refused the activity', ['club' => $task['club'], 'target' => $url, 'code' => isset($curl) ? $curl['status'] : 0]);
    elseif ($result == 'local-dns') Club_Log_Event('debug', 'push deferred, waiting for local dns', ['club' => $task['club'], 'target' => $url, 'retry' => 300]);
    elseif ($result == 'local-proxy') Club_Log_Event('warning', 'push deferred, outbound proxy unavailable', ['club' => $task['club'], 'target' => $url, 'retry' => 300]);
    elseif ($result == 'dropped') Club_Log_Event('debug', 'queue dropped without contacting the target', ['club' => $task['club'], 'target' => $url]);
    elseif ($state == 'exhausted') Club_Log_Event('warning', 'push dropped after '.$task['retries'].' failed attempts', ['club' => $task['club'], 'target' => $url, 'reason' => $result]);
    elseif ($state == 'blacklisted') Club_Log_Event('debug', 'push held, endpoint was blacklisted', ['club' => $task['club'], 'target' => $url, 'reason' => $result]);
    else Club_Log_Event('debug', 'push failed, will retry', ['club' => $task['club'], 'target' => $url, 'reason' => $result, 'retries' => (int)$task['retries'], 'retry' => $plan['retry_at'] - $now]);
    return true;
}

function Club_Endpoint_Schedule($url, $retry_at) {
    global $db;
    $empty = ['next_at' => null, 'follow_at' => null, 'notice_at' => null, 'announce_at' => null, 'relay_at' => null];
    $pdo = $db->prepare('select 1 from `blacklist` where `target` = :url');
    $pdo->execute([':url' => $url]);
    if ($pdo->fetch(PDO::FETCH_COLUMN, 0)) { Club_Stat('scheduler_db_ops'); return $empty; }
    $pdo = $db->prepare('select (select min(`due_at`) from `queues` where `target` = :url and `type` = \'follow\') as `follow_at`,'.
        ' (select min(`due_at`) from `queues` where `target` = :url and `type` = \'notice\') as `notice_at`,'.
        ' (select min(`due_at`) from `queues` where `target` = :url and `type` = \'announce\') as `announce_at`,'.
        ' (select min(`due_at`) from `queues` where `target` = :url and `type` = \'relay\') as `relay_at`');
    $pdo->execute([':url' => $url]); $row = $pdo->fetch(PDO::FETCH_ASSOC) ?: $empty;
    Club_Stat('scheduler_db_ops', 2);
    foreach (Club_Task_Category_List() as $category) $empty[$category.'_at'] = isset($row[$category.'_at']) ? (int)$row[$category.'_at'] : null;
    $due = array_filter(array_values($empty), function ($value) { return isset($value); });
    if ($due) $empty['next_at'] = max((int)$retry_at, min($due));
    return $empty;
}

function Club_Endpoint_Sweep($limit = 200) {
    global $db; static $phase = 0, $cursor = '';
    $now = time(); $seen = 0; $repairs = 0; $pruned = 0;
    if ($phase === 0) {
        $pdo = $db->prepare('select `q`.`target` from `queues` `q` left join `blacklist` `b` on `q`.`target` = `b`.`target`'.
            ' where `b`.`target` is null and `q`.`target` > :cursor group by `q`.`target` order by `q`.`target` limit '.(int)$limit);
        $pdo->execute([':cursor' => $cursor]);
        foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $target) {
            $cursor = $target; $seen++;
            if (Club_Endpoint_Repair($target, $now)) $repairs++;
        }
    } else {
        $pdo = $db->prepare('select `url` from `endpoints` where `url` > :cursor and `lease_until` <= :now order by `url` limit '.(int)$limit);
        $pdo->execute([':cursor' => $cursor, ':now' => $now]);
        foreach ($pdo->fetchAll(PDO::FETCH_COLUMN, 0) as $url) {
            $cursor = $url; $seen++;
            if (($took = Club_Endpoint_Prune($url, $now)) === 'pruned') $pruned++;
            elseif ($took) $repairs++;
        }
    }
    Club_Stat('scheduler_db_ops');
    Club_Monitor_Count('reconciliation_repairs', $repairs);
    Club_Monitor_Count('endpoints_pruned', $pruned);
    if ($seen < $limit) { $phase = ($phase + 1) % 2; $cursor = ''; }
    return $seen;
}

function Club_Endpoint_Upsert($task, $category, &$empty = null) {
    global $db;
    if (!in_array($category, Club_Task_Category_List(), true)) throw new InvalidArgumentException('Unknown delivery category: '.$category);
    $pdo = $db->prepare('insert into `endpoints` (`url`, `next_at`, `'.$category.'_at`) select `incoming`.`url`, `incoming`.`next_at`, `incoming`.`next_at` from ('.
        ' select `q`.`target` collate ascii_bin as `url`, min(`q`.`due_at`) as `next_at` from `queues` as `q` where `q`.`tid` = :tid group by `q`.`target` collate ascii_bin'.
        ') as `incoming` order by `incoming`.`url` collate ascii_bin on duplicate key update `next_at` = greatest(`endpoints`.`retry_at`,'.
        ' if(`endpoints`.`next_at` is null, `incoming`.`next_at`, least(`endpoints`.`next_at`, `incoming`.`next_at`))), `'.$category.'_at` ='.
        ' if(`endpoints`.`'.$category.'_at` is null, `incoming`.`next_at`, least(`endpoints`.`'.$category.'_at`, `incoming`.`next_at`)), `idle_since` = 0');
    if (!$pdo->execute([':tid' => $task])) return false;
    $pdo = $db->prepare('select `b`.`target` from `queues` `q` join `blacklist` `b` on `b`.`target` = `q`.`target` where `q`.`tid` = :tid order by `b`.`target` for update');
    $pdo->execute([':tid' => $task]); $blocked = $pdo->fetchAll(PDO::FETCH_COLUMN); $empty = false;
    foreach (array_chunk($blocked, 250) as $chunk) {
        $targets = []; foreach ($chunk as $i => $target) $targets[':url'.$i] = $target;
        $in = implode(',', array_keys($targets));
        $pdo = $db->prepare('update `endpoints` set `next_at` = null, `follow_at` = null, `notice_at` = null, `announce_at` = null, `relay_at` = null,'.
            ' `idle_since` = if(`idle_since` > 0, `idle_since`, :now), `lease_until` = 0, `lease_token` = null where `url` in ('.$in.')');
        $pdo->execute([':now' => time()] + $targets);
        $pdo = $db->prepare('delete from `queues` where `tid` = :tid and `target` in ('.$in.')'); $pdo->execute([':tid' => $task] + $targets);
    }
    $pdo = $db->prepare('delete from `tasks` where `tid` = :tid and not exists (select 1 from `queues` where `tid` = :check)'); $pdo->execute([':tid' => $task, ':check' => $task]);
    $empty = (bool)$pdo->rowCount();
    return true;
}

function Club_Delivery_Enqueue($signer, $clubs, $activity, $category, $direct, $inbox, $origin, &$error, &$reason) {
    global $db;
    if (!($task = Club_Task_Create($category, $signer, $activity))) {
        $reason = 'club-missing'; $error = 'club not found: '.$signer;
        return false;
    }
    $queued = $direct ? Club_Queue_Insert($task, $inbox, $category) : Club_Queue_Fanout($task, $category, $clubs, $inbox, $origin);
    if ($queued === null) {
        $pdo = $db->prepare('delete from `tasks` where `tid` = :tid and not exists (select 1 from `queues` where `tid` = :tid_check)');
        $pdo->execute([':tid' => $task, ':tid_check' => $task]);
        $reason = 'blacklisted'; $error = 'target is blacklisted: '.$inbox;
        return false;
    }
    if ($queued === false) throw new PDOException('queue insert returned false');
    if (!Club_Endpoint_Upsert($task, $category, $empty)) throw new PDOException('endpoint upsert returned false');
    if ($direct && $empty) { $reason = 'blacklisted'; $error = 'target became blacklisted'; return false; }
    return true;
}

function Club_Delivery_Queue($club, $activity, $inbox = false, $direct = false, $type = null, $origin = false, &$reason = null) {
    global $db;
    $reason = null; $clubs = array_values((array)$club); $club = $clubs[0];
    $category = Club_Task_Category($activity, $direct);
    // Re-encoding a relayed activity can invalidate its LD signature.
    if (is_array($activity)) { $type = $activity['type']; $activity = Club_Json_Encode($activity); $signer = $club; }
    else $signer = Club_Group_Signer() ?: $club;
    if ($direct && ($inbox = Club_Endpoint_Require($inbox, ['club' => $club, 'type' => $type])) === false) {
        $reason = 'invalid-endpoint';
        Club_Log_Event('error', 'push failed, target inbox is not a usable endpoint', ['club' => $club, 'type' => $type ?: 'unknown']);
        return false;
    }
    if (!$direct && $inbox !== false && ($inbox = Club_Endpoint_Normalize($inbox)) === false) {
        Club_Log_Event('warning', 'push author inbox skipped, url cannot be normalized', ['clubs' => $clubs]);
        $inbox = false;
    }
    $name = Club_Log_Name('outbox', [implode('-', $clubs), $type ?: 'unknown']);
    Club_Log_Write('info', 'outbox', $name.'_output', $activity);
    Club_Log_Write('debug', 'outbox', $name.'_server', $_SERVER);
    $commit = false; $error = ''; $owned = !$db->inTransaction();
    try {
        if ($owned) $commit = Club_DB_Transaction('push enqueue', function () use ($signer, $clubs, $activity, $category, $direct, $inbox, $origin, &$error, &$reason) {
                return Club_Delivery_Enqueue($signer, $clubs, $activity, $category, $direct, $inbox, $origin, $error, $reason);
            });
        else $commit = Club_Delivery_Enqueue($signer, $clubs, $activity, $category, $direct, $inbox, $origin, $error, $reason);
    } catch (PDOException $e) {
        $error = $e->getMessage();
        if ($owned) {
            Club_Log_Event('error', 'push failed: '.$error);
            Club_Log_Write('error', 'outbox', $name.'_commit_failed', $error, 'txt');
        }
        throw $e;
    }
    if (!$commit) {
        Club_Log_Write('error', 'outbox', $name.'_commit_failed', $error ?: 'commit returned false', 'txt');
    } else Club_Log_Event('debug', 'push queued, '.($type ?: 'unknown'), ['clubs' => $clubs, 'signer' => $signer, 'target' => $direct ? $inbox : 'followers', 'outbox' => $name]);
    return (bool)$commit;
}

function Club_Blacklist_Add($url, $now) {
    global $db;
    $check = $now + mt_rand(0, 86400);
    $pdo = $db->prepare('insert ignore into `blacklist`(`target`,`created_at`,`check_at`,`checks`) values (:target, :now, :check, 0)');
    $pdo->execute([':target' => $url, ':now' => $now, ':check' => $check]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

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

function Club_Blacklist_Claim($now, $lease = 120) {
    global $db;
    $pdo = $db->prepare('select `target` from `blacklist` where `restore_pending_at` is null and `check_at` <= :now and `lease_until` <= :now order by `check_at` limit 32');
    $pdo->execute([':now' => $now]);
    $candidates = $pdo->fetchAll(PDO::FETCH_COLUMN, 0);
    Club_Stat('scheduler_db_ops');
    if (!$candidates) return null;
    $token = Club_Lease_Token();
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

function Club_Blacklist_Cleanup($limit = 500) {
    global $db; $now = time(); $rows = 0; $state = '';
    $pdo = $db->query('select `target`, `restore_pending_at` from `blacklist` where `restore_pending_at` is not null'.
        ' or exists (select 1 from `queues` where `queues`.`target` = `blacklist`.`target`) or exists (select 1 from `endpoints` where `endpoints`.`url` = `blacklist`.`target`)'.
        ' order by `restore_pending_at` is null, exists (select 1 from `queues` where `queues`.`target` = `blacklist`.`target`) desc, `created_at` limit 1');
    Club_Stat('scheduler_db_ops');
    if (!($row = $pdo->fetch(PDO::FETCH_ASSOC))) return 0;
    $target = $row['target'];
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `url` from `endpoints` where `url` = :target for update');
        $pdo->execute([':target' => $target]);
        $pdo = $db->prepare('select `restore_pending_at` from `blacklist` where `target` = :target for update');
        $pdo->execute([':target' => $target]);
        if (($row = $pdo->fetch(PDO::FETCH_ASSOC)) !== false) {
            $pdo = $db->prepare('delete from `queues` where `target` = :target limit '.(int)$limit);
            $pdo->execute([':target' => $target]);
            $rows = $pdo->rowCount();
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
    if ($state === '' && $rows < $limit && isset($row['restore_pending_at'])) Club_Blacklist_Restore($target, $now);
    return $rows;
}

function Club_Blacklist_Decide($alive, $checks, $queued, $now, $jitter = null) {
    if ($alive) return ['state' => $queued ? 'pending' : 'restored', 'checks' => (int)$checks, 'check_at' => null];
    $checks = (int)$checks + 1; $wait = 86400 * min($checks, 7);
    return ['state' => 'dead', 'checks' => $checks, 'check_at' => $now + $wait + (isset($jitter) ? (int)$jitter : mt_rand(0, (int)($wait / 4)))];
}

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

function Club_Blacklist_Force($target, $check = 4111110000) {
    global $db; $now = time();
    if (($target = Club_Endpoint_Require($target, ['source' => 'cli blacklist'])) === false) return false;
    try {
        $db->beginTransaction();
        $pdo = $db->prepare('select `url` from `endpoints` where `url` = :target for update'); $pdo->execute([':target' => $target]);
        $pdo = $db->prepare('select `target` from `blacklist` where `target` = :target for update'); $pdo->execute([':target' => $target]);
        $pdo = $db->prepare('insert into `blacklist`(`target`,`created_at`,`check_at`,`checks`,`restore_pending_at`,`lease_until`,`lease_token`)'.
            ' values (:target, :now, :check, 0, null, 0, null) on duplicate key update `check_at` = values(`check_at`), `restore_pending_at` = null, `lease_until` = 0, `lease_token` = null');
        $pdo->execute([':target' => $target, ':now' => $now, ':check' => (int)$check]);
        $pdo = $db->prepare('update `endpoints` set `next_at` = null, `follow_at` = null, `notice_at` = null, `announce_at` = null, `relay_at` = null,'.
            ' `idle_since` = if(`idle_since` > 0, `idle_since`, :now), `lease_until` = 0, `lease_token` = null where `url` = :target');
        $pdo->execute([':target' => $target, ':now' => $now]);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        throw $e;
    }
    Club_Log_Event('info', 'endpoint blacklisted by cli', ['target' => $target, 'check_at' => (int)$check]);
    return true;
}

function Club_Blacklist_Probe_Now($targets) {
    global $db; $now = time(); $rows = 0;
    $targets = array_values(array_unique((array)$targets)); sort($targets, SORT_STRING);
    $pdo = $db->prepare('update `blacklist` set `check_at` = :now where `target` = :target and `restore_pending_at` is null');
    foreach ($targets as $target) { $pdo->execute([':target' => $target, ':now' => $now]); $rows += $pdo->rowCount(); }
    Club_Log_Event($rows ? 'info' : 'debug', 'blacklist probes requested by cli', ['targets' => count($targets), 'updated' => $rows]);
    return $rows;
}

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
    if ($state == 'restored') Club_Log_Event('info', 'endpoint restored from blacklist: '.$target, ['checks' => $checks, 'endpoint' => $endpoint]);
    elseif ($state == 'pending') Club_Log_Event('info', 'recovery confirmed, cleanup pending: '.$target, ['checks' => $checks]);
    else Club_Log_Event('debug', 'blacklist probe found target still down', ['target' => $target, 'checks' => $checks]);
    return $state;
}

function Club_Blacklist_Sooner($actor) {
    global $db; $now = time(); $check = $now + 3600 + mt_rand(0, 300);
    $pdo = $db->prepare('update `blacklist` `b` join (select `inbox` as `target` from `users` where `actor` = :actor union select `shared_inbox` from `users` where `actor` = :owner)'.
        ' `u` on `b`.`target` = `u`.`target` set `b`.`check_at` = :check where `b`.`restore_pending_at` is null and `b`.`check_at` > :cutoff and `b`.`lease_until` <= :now');
    $pdo->execute([':actor' => $actor, ':owner' => $actor, ':check' => $check, ':cutoff' => $now + 3900, ':now' => $now]);
    Club_Stat('scheduler_db_ops');
    if (!$pdo->rowCount()) return false;
    Club_Log_Event('info', 'blacklist probe pulled in by inbound activity', ['actor' => $actor, 'rows' => $pdo->rowCount(), 'wait' => $check - $now]);
    return true;
}

function Club_DB_Connect() {
    global $db, $config;
    $options = [PDO::ATTR_PERSISTENT => PHP_SAPI != 'cli', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if (PHP_SAPI == 'cli') $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'set session transaction isolation level read committed';
    return $db = new PDO('mysql:host='.$config['mysql']['host'].';dbname='.$config['mysql']['database'].';charset=utf8mb4', $config['mysql']['username'], $config['mysql']['password'], $options);
}

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

function Club_DB_Transaction($what, $run) {
    global $db;
    if ($db->inTransaction()) return $run();
    return Club_DB_Retry($what, function () use ($db, $run, $what) {
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
        if ($e->getCode() === '42S02' || (int)($e->errorInfo[1] ?? 0) === 1146) return 0;
        throw $e;
    }
}

function Club_HTTP_Accept_ActivityPub($accept) {
    $ap = 0; $html = 0;
    foreach (explode(',', strtolower(is_scalar($accept) ? (string)$accept : '')) as $range) {
        $params = array_map('trim', explode(';', $range)); $type = array_shift($params); $q = 1;
        foreach ($params as $param) if (strncmp($param, 'q=', 2) === 0) $q = (float)substr($param, 2);
        if ($type === 'application/activity+json' || $type === 'application/json' || ($type === 'application/ld+json' && strpos($range, 'activitystreams') !== false)) $ap = max($ap, $q);
        elseif ($type === 'text/html') $html = max($html, $q);
    }
    return $ap > $html;
}

function Club_HTTP_Cursor($cursor) {
    return is_string($cursor) && preg_match('/^(\d{1,10})\.(\d{1,10})$/D', $cursor, $m) && $m[1] <= 2147483647 && $m[2] <= 4294967295 ? [(int)$m[1], (int)$m[2]] : false;
}

// A lost response can follow a successful POST.
function Club_HTTP_Fallback($post, $status, $network_error, $unsent) {
    if ($status === 403) return true;
    if ($post) return !$status && $network_error && $unsent;
    if ($status >= 400) return $status >= 500 && $status < 600 && $status !== 501;
    return $network_error;
}

function Club_HTTP_Full_Document($code, $headers) {
    return $code >= 200 && $code < 300 && $code !== 206 && Club_HTTP_Header($headers, 'Content-Range') === '';
}

function Club_HTTP_Header($headers, $name) {
    foreach ((array)$headers as $k => $v) if (strcasecmp($k, $name) === 0) return $v;
    return '';
}

function Club_HTTP_Page($query, $outbox) {
    $page = $query['page'] ?? null;
    if (isset($page) && !in_array($page, ['last', 'true'], true)) return false;
    $parsed = ['tail' => $page === 'last', 'min' => false, 'max' => false];
    foreach (['min', 'max'] as $key) if (array_key_exists($key, $query)) {
        if (($outbox && $page !== 'true') || $parsed['tail'] || !($parsed[$key] = Club_HTTP_Cursor($query[$key]))) return false;
    }
    return $parsed['min'] && $parsed['max'] ? false : $parsed;
}

function Club_HTTP_Proxy_Result($errno, $message, $connect, $connected, $sent) {
    if ($sent) return 'failed';
    if ($connect && ($connect < 200 || $connect >= 300)) return in_array($connect, [502, 504]) ? 'next-address' : 'local-proxy';
    if (in_array($errno, [CURLE_COULDNT_CONNECT, 97]) && preg_match('/SOCKS5 connection to .+ \((\d+)\)/i', $message, $reply))
        return in_array((int)$reply[1], [3, 4, 5, 6, 8]) ? 'next-address' : 'local-proxy';
    if ($errno == 97 || $errno == CURLE_COULDNT_RESOLVE_PROXY || (!$connected && in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEOUTED]))
        || stripos($message, 'authentication method') !== false || stripos($message, 'rejected by the SOCKS5 server') !== false) return 'local-proxy';
    return 'failed';
}

function Club_HTTP_Request($handle, $url, $headers, $options, $data = null, $ips = [], &$pinned = null, $traffic = 'federation', $reauthorize = null) {
    $routes = Club_Config_Proxy_Routes($traffic);
    $budget = $traffic === 'doh' || isset($data) ? 65536 : 1048576;
    $first = Club_HTTP_Transfer($handle, $url, $headers, $options, $data, $ips, $pinned, $routes[0], $budget);
    if (!isset($routes[1]) || !$first['fallback']) return $first;
    Club_Log_Event('debug', 'outbound request switching route', ['traffic' => $traffic, 'url' => $url, 'next' => $routes[1] === '' ? 'direct' : 'proxy', 'http' => $first['status'], 'error' => $first['message']]);
    if (isset($reauthorize) && !$reauthorize()) return $first + ['lease_lost' => true];
    if (isset($data) && $traffic === 'federation') Club_Stat('http_requests');
    $second = Club_HTTP_Transfer($handle, $url, $headers, $options, $data, $ips, $pinned, $routes[1], $budget);
    if ($second['status'] === 403 && $first['status'] !== 403) {
        Club_Log_Event('debug', 'alternate route returned 403, keeping retryable failure', ['traffic' => $traffic, 'url' => $url]);
        return $first;
    }
    return $second;
}

function Club_HTTP_Respond($data, $type = 'json', $status = 200) {
    header('Content-type: application/'.$type.'; charset=utf-8');

    if ($status != 200) {
        http_response_code($status);
        $data = array_merge(['code' => $status], $data);
    } echo Club_Json_Encode($data);
}

// Pin the proxy tunnel to the verified IP while preserving the TLS hostname.
function Club_HTTP_Route($url, $ips, $proxy, &$pinned) {
    $options = [CURLOPT_PROXY => $proxy];
    $resolve = isset($pinned) ? ['-'.$pinned] : []; $pinned = null;
    $parts = parse_url($url);
    if ($proxy !== '') {
        $options[CURLOPT_NOPROXY] = '';
        $options[CURLOPT_HTTPPROXYTUNNEL] = true;
        if (defined('CURLOPT_SUPPRESS_CONNECT_HEADERS')) $options[CURLOPT_SUPPRESS_CONNECT_HEADERS] = true;
        if ($ips) {
            $ip = reset($ips);
            $options[CURLOPT_CONNECT_TO] = ['::'.(strpos($ip, ':') === false ? $ip : '['.$ip.']').':'];
        }
    } elseif ($ips && !filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP)) {
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'http' ? 80 : 443);
        foreach ($ips as $i => $ip) if (strpos($ip, ':') !== false) $ips[$i] = '['.$ip.']';
        $resolve[] = ($pinned = $parts['host'].':'.$port).':'.implode(',', $ips);
    }
    if ($resolve) $options[CURLOPT_RESOLVE] = $resolve;
    return $options;
}

function Club_HTTP_Transfer($handle, $url, $headers, $options, $data, $ips, &$pinned, $proxy, $budget = 1048576) {
    curl_reset($handle);
    $options[CURLOPT_URL] = $url;
    $options[CURLOPT_PATH_AS_IS] = true;
    if (defined('CURLOPT_REQUEST_TARGET') && ($fields = ActivityPub_Sign_Fields($url))) $options[CURLOPT_REQUEST_TARGET] = $fields['target'];
    $options[CURLOPT_HTTPHEADER] = array_map(function ($key, $value) { return $key.': '.$value; }, array_keys($headers), array_values($headers));
    $options[CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) use (&$body, &$received, &$limit, $budget, $data) {
        $length = strlen($chunk); $received += $length;
        if ($received > $budget) { $limit = 'body'; return 0; }
        if (!isset($data)) $body .= $chunk;
        return $length;
    };
    $options[CURLOPT_HEADERFUNCTION] = function ($ch, $line) use (&$response_headers, &$raw_headers, &$fields_count, &$status, &$block, &$skip_connect, &$limit) {
        $length = strlen($line); $raw_headers += $length;
        if ($raw_headers > 65536) { $limit = 'headers'; return 0; }
        $line = rtrim($line, "\r\n");
        if (preg_match('~^HTTP/\S+ ([0-9]{3})(?: |$)~i', $line, $m)) { $block = (int)$m[1]; $status = 0; $response_headers = ['Status-Line' => $line]; }
        elseif ($line === '' && $block) {
            if ($skip_connect && $block >= 200) $skip_connect = false;
            elseif ($block >= 200) $status = $block;
            $block = 0;
        } elseif (strpos($line, ':') !== false) {
            if (++$fields_count > 128) { $limit = 'fields'; return 0; }
            if (!$block) return $length;
            list($key, $value) = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key); $response_headers[$key] = isset($response_headers[$key]) ? $response_headers[$key].','.$value : $value;
        }
        return $length;
    };
    $options[CURLOPT_POST] = isset($data);
    $options[CURLOPT_HTTPGET] = !isset($data);
    if (isset($data)) $options[CURLOPT_POSTFIELDS] = $data;
    $candidates = $proxy !== '' && $ips ? array_values($ips) : [null];
    $deadline = microtime(true) + ($options[CURLOPT_TIMEOUT] ?? 10);
    $proxy_error = false; $unsent = false;
    foreach ($candidates as $index => $ip) {
        if ($index) {
            $left = (int)(($deadline - microtime(true)) * 1000);
            if ($left < 1) break;
            $options[CURLOPT_TIMEOUT_MS] = $left;
        }
        $response_headers = []; $status = $block = $raw_headers = $fields_count = $received = 0; $body = ''; $limit = null;
        $skip_connect = stripos($proxy, 'http://') === 0 && !defined('CURLOPT_SUPPRESS_CONNECT_HEADERS');
        if (!curl_setopt_array($handle, Club_HTTP_Route($url, isset($ip) ? [$ip] : $ips, $proxy, $pinned) + $options))
            return ['status' => 0, 'headers' => [], 'limit' => null, 'body_bytes' => 0, 'header_bytes' => 0, 'response' => false, 'curl_error' => true,
                'error' => true, 'proxy_error' => $proxy !== '', 'fallback' => false, 'message' => 'could not configure outbound request'];
        curl_exec($handle); $curl_error = curl_errno($handle);
        $message = $curl_error ? curl_error($handle) : '';
        $unsent = false;
        if (!$curl_error) break;
        $sent = $status || (isset($data) && curl_getinfo($handle, CURLINFO_SIZE_UPLOAD) > 0);
        $connected = (bool)curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        $written = curl_getinfo($handle, CURLINFO_REQUEST_SIZE);
        $unsent = !$sent && !$written && (in_array($curl_error, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT])
            || ($curl_error === CURLE_OPERATION_TIMEOUTED && !$connected && !curl_getinfo($handle, CURLINFO_CONNECT_TIME)));
        if ($proxy === '') break;
        $connect = (int)curl_getinfo($handle, CURLINFO_HTTP_CONNECTCODE);
        $result = Club_HTTP_Proxy_Result($curl_error, $message, $connect, $connected, $sent || (!$connect && $written));
        $proxy_error = $result === 'local-proxy';
        if ($proxy_error || $result === 'next-address') $unsent = true;
        $message = ($proxy_error ? 'outbound proxy unavailable' : curl_strerror($curl_error)).' (curl '.$curl_error.', CONNECT '.$connect.')';
        if ($result !== 'next-address') break;
    }
    $http_error = in_array((int)floor($status / 100), [4, 5], true); $error = (bool)$curl_error || $http_error;
    if (!$curl_error && $http_error) $message = $response_headers['Status-Line'] ?? '';
    $network_error = $proxy_error || in_array($curl_error, [CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_WEIRD_SERVER_REPLY, CURLE_PARTIAL_FILE,
        CURLE_OPERATION_TIMEOUTED, CURLE_SSL_CONNECT_ERROR, CURLE_GOT_NOTHING, CURLE_SEND_ERROR, CURLE_RECV_ERROR, CURLE_SSL_CACERT, 16, 92, 97], true);
    return ['status' => $status, 'headers' => $response_headers, 'body_bytes' => $received, 'header_bytes' => $raw_headers, 'limit' => $limit,
        'response' => $curl_error ? false : $body, 'curl_error' => (bool)$curl_error, 'error' => $error, 'proxy_error' => $proxy_error,
        'fallback' => Club_HTTP_Fallback(isset($data), $status, $network_error, $unsent), 'message' => $message];
}

// An empty list is authoritative. Null means SERVFAIL and false means no usable reply.
function Club_Resolver_Answer($json, $type) {
    if (!is_array($json) || !isset($json['Status'])) return false;
    if ($json['Status'] === 2) return null;
    if ($json['Status'] !== 0 && $json['Status'] !== 3) return false;
    if (!empty($json['TC'])) return false;
    if (isset($json['Answer']) && !is_array($json['Answer'])) return false;
    $want = $type === 'AAAA' ? 28 : 1; $ips = []; $seen = 0; $junk = false;
    foreach ($json['Answer'] ?? [] as $rr) {
        if (!is_array($rr)) { $junk = true; continue; }
        if ((int)($rr['type'] ?? 0) !== $want) continue;
        $seen++;
        if (is_string($ip = $rr['data'] ?? null) && filter_var($ip, FILTER_VALIDATE_IP, $want === 28 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4)) {
            $ip = inet_ntop(inet_pton($ip)); if (count($ips) < 8) $ips[$ip] = $ip;
        }
    }
    if ($junk || ($seen && (!$ips || $json['Status'] === 3))) return false;
    return array_values($ips);
}

function Club_Resolver_Budget() {
    global $config;
    return max(1, (int)($config['dns']['timeout'] ?? 5)) * 2 * max(1, count(Club_Resolver_List())) * count(Club_Config_Proxy_Routes('doh'));
}

function Club_Resolver_Cached($host, $row, $now, $miss) {
    if ($row['ips'] !== '') {
        Club_Stat('dns_positive'); Club_Resolver_Healthy($row['checked_at']);
        return explode(',', $row['ips']);
    }
    Club_Stat('dns_negative');
    Club_Log_Event('debug', 'dns lookup skipped, negative cache still fresh', ['host' => $host, 'age' => $now - $row['checked_at'], 'retry' => $row['checked_at'] + $miss - $now]);
    return [];
}

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

function Club_Resolver_Cleanup($ttl = 86400, $limit = 200) {
    global $db; $now = time(); $expire = $now - $ttl;
    $pdo = $db->prepare('select `host` from `dns` where `lock_until` <= :now and `checked_at` <= :expire limit '.(int)$limit);
    $pdo->execute([':now' => $now, ':expire' => $expire]);
    $hosts = $pdo->fetchAll(PDO::FETCH_COLUMN, 0);
    Club_Stat('scheduler_db_ops');
    if (!$hosts) {
        Club_Log_Event('debug', 'dns cache has nothing expired to evict', ['age' => $ttl]);
        return 0;
    }
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

function Club_Resolver_Deferred($set = null, &$reason = null) {
    static $deferred = false;
    if (isset($set)) $deferred = $set === true ? 'contention' : $set;
    $reason = $deferred;
    return (bool)$deferred;
}

function Club_Resolver_DoH($resolver, $host, $type) {
    global $config;
    static $handle = null, $pinned = null;
    if (!isset($handle)) $handle = curl_init();
    $options = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => max(1, (int)($config['dns']['timeout'] ?? 5)), CURLOPT_CONNECTTIMEOUT => max(1, (int)($config['dns']['connect-timeout'] ?? 3))];
    $curl = Club_HTTP_Request($handle, $resolver['url'].'?name='.urlencode($host).'&type='.$type, ['Accept' => 'application/dns-json'], $options, null, $resolver['ip'] ?? [], $pinned, 'doh');
    $json = json_decode((string)$curl['response'], 1);
    $answer = !$curl['error'] && Club_HTTP_Full_Document($curl['status'], $curl['headers']) ? Club_Resolver_Answer($json, $type) : false;
    if ($answer === null) Club_Log_Event('info', 'doh reports the domain fails to resolve', ['resolver' => $resolver['url'], 'host' => $host, 'type' => $type]);
    elseif ($answer === false)
        Club_Log_Event('warning', 'doh query failed', ['resolver' => $resolver['url'], 'host' => $host, 'type' => $type,
            'http' => $curl['status'], 'status' => is_array($json) ? ($json['Status'] ?? '?') : '?', 'error' => $curl['message']]);
    return $answer;
}

function Club_Resolver_Get($host, $ttl = 300, $miss = 60, $stale = 3600) {
    $now = time(); Club_Resolver_Deferred(false);
    if (!is_string($host) || ($host = Club_Url_Host($host)) === false) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
    $row = Club_Resolver_Read($host);
    if ($row && $row['checked_at'] > $now - ($row['ips'] === '' ? $miss : $ttl)) return Club_Resolver_Cached($host, $row, $now, $miss);
    $stock = $row && $row['ips'] !== '' && $row['checked_at'] > $now - $stale ? $row['ips'] : null;
    if (!Club_Resolver_Claim($host, $now, $token = Club_Lease_Token(), max(30, Club_Resolver_Budget() + 10))) {
        Club_Stat('dns_contention');
        if (isset($stock)) {
            Club_Stat('dns_stale');
            Club_Log_Event('debug', 'dns refresh deferred, another worker holds the lock', ['host' => $host, 'age' => $now - $row['checked_at'], 'ip' => $stock]);
            return explode(',', $stock);
        }
        usleep(mt_rand(200000, 1000000)); $now = time();
        if (($row = Club_Resolver_Read($host)) && $row['checked_at'] > $now - ($row['ips'] === '' ? $miss : $ttl)) return Club_Resolver_Cached($host, $row, $now, $miss);
        Club_Stat('dns_deferred'); Club_Resolver_Deferred(true);
        Club_Log_Event('debug', 'dns refresh deferred, no committed result yet', ['host' => $host]);
        return false;
    }
    $ips = Club_Resolver_Query($host);
    if ($ips === false) {
        Club_Resolver_Release($host, $token);
        if (isset($stock)) {
            Club_Stat('dns_stale');
            Club_Log_Event('debug', 'dns lookup failed, reusing cached address', ['host' => $host, 'age' => $now - $row['checked_at'], 'ip' => $stock]);
            return explode(',', $stock);
        }
        Club_Stat('dns_unreachable'); Club_Resolver_Deferred('unreachable');
        Club_Log_Event('warning', 'dns lookup failed, no resolver answered', ['host' => $host]);
        return false;
    }
    if (!Club_Resolver_Store($host, $token, $ips ? implode(',', $ips) : '', time())) {
        Club_Stat('dns_stale_store');
        Club_Log_Event('debug', 'stale dns result discarded', ['host' => $host, 'token' => $token]);
        if (($row = Club_Resolver_Read($host)) && $row['checked_at'] > time() - ($row['ips'] === '' ? $miss : $stale)) return Club_Resolver_Cached($host, $row, time(), $miss);
        Club_Resolver_Deferred(true);
        return false;
    }
    if ($ips) { Club_Stat('dns_positive'); Club_Resolver_Healthy(true); return $ips; }
    Club_Stat('dns_negative');
    Club_Log_Event('debug', 'dns lookup returned no records', ['host' => $host]);
    return [];
}

function Club_Resolver_Healthy($mark = false, $window = 600) {
    global $db, $config; static $last = 0, $checked = 0;
    if ($mark !== false) { $last = max($last, $mark === true ? time() : (int)$mark); return true; }
    if ($last > time() - $window) return true;
    if ($checked > time() - 10) return false;
    $checked = time();
    $pdo = $db->prepare('select `checked_at` from `dns` where length(`ips`) > 0 and `checked_at` > :window limit 1');
    $pdo->execute([':window' => time() - $window]);
    if ($resolved = $pdo->fetch(PDO::FETCH_COLUMN, 0)) { $last = max($last, (int)$resolved); return true; }
    Club_Resolver_Get(parse_url('https://'.$config['base'], PHP_URL_HOST));
    return $last > time() - $window;
}

function Club_Resolver_List() {
    global $config;
    return !empty($config['dns']['resolver']) ? $config['dns']['resolver'] : [['url' => 'https://one.one.one.one/dns-query', 'ip' => ['1.1.1.1', '1.0.0.1', '2606:4700:4700::1111', '2606:4700:4700::1001']],
        ['url' => 'https://dns.google/resolve', 'ip' => ['8.8.8.8', '8.8.4.4', '2001:4860:4860::8888', '2001:4860:4860::8844']]];
}

function Club_Resolver_Query($host) {
    static $seq = 0;
    $ref = getmypid().'-'.++$seq; $start = microtime(true); $ips = false; $used = ''; $servfail = 0;
    Club_Log_Event('debug', 'resolver_query_started', ['sapi' => PHP_SAPI, 'slot' => Club_Worker_Slot(), 'pid' => getmypid(), 'host' => $host, 'query_ref' => $ref]);
    foreach (Club_Resolver_List() as $resolver) {
        $a = Club_Resolver_DoH($resolver, $host, 'A');
        if ($a === null) { $servfail++; $a = false; }
        $aaaa = Club_Resolver_DoH($resolver, $host, 'AAAA');
        if ($aaaa === null) { $servfail++; $aaaa = false; }
        $found = array_values(array_unique(array_merge(is_array($a) ? $a : [], is_array($aaaa) ? $aaaa : [])));
        if (!$found && ($a === false || $aaaa === false)) continue;
        $ips = $found; $used = $resolver['url'];
        break;
    }
    if ($ips === false && $servfail) Club_Stat('dns_servfail');
    $ms = (microtime(true) - $start) * 1000;
    Club_Stat('dns_queries'); Club_Stat_Sample('resolver_query_ms', $ms);
    Club_Log_Event('debug', 'resolver_query_finished', ['query_ref' => $ref, 'ms' => round($ms), 'resolver' => $used, 'found' => $ips === false ? -1 : count($ips)]);
    return $ips;
}

function Club_Resolver_Read($host) {
    global $db;
    $pdo = $db->prepare('select `ips`, `checked_at`, `lock_until` from `dns` where `host` = :host');
    $pdo->execute([':host' => $host]); Club_Stat('scheduler_db_ops');
    $row = $pdo->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['ips'] === '') return $row ?: null;
    $addresses = [4 => [], 16 => []];
    foreach (explode(',', $row['ips']) as $ip) if (($bin = @inet_pton($ip)) !== false && count($addresses[$n = strlen($bin)]) < 8) {
        $ip = inet_ntop($bin); $addresses[$n][$ip] = $ip;
    }
    $row['ips'] = implode(',', array_merge(array_values($addresses[4]), array_values($addresses[16])));
    if ($row['ips'] === '') $row['checked_at'] = 0;
    return $row;
}

function Club_Resolver_Release($host, $token) {
    global $db;
    $pdo = $db->prepare('update `dns` set `lock_until` = 0, `lock_token` = null where `host` = :host and `lock_token` = unhex(:token)');
    $pdo->execute([':host' => $host, ':token' => $token]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

function Club_Resolver_Store($host, $token, $ips, $now) {
    global $db;
    $pdo = $db->prepare('update `dns` set `ips` = :ips, `checked_at` = :now, `lock_until` = 0, `lock_token` = null where `host` = :host and `lock_token` = unhex(:token)');
    $pdo->execute([':host' => $host, ':token' => $token, ':ips' => $ips, ':now' => $now]);
    Club_Stat('scheduler_db_ops');
    return (bool)$pdo->rowCount();
}

function Club_Config_Delivery_Workers() {
    global $config;
    $defaults = ['follow' => 2, 'notice' => 2, 'announce' => 2, 'relay' => 2]; $value = $config['worker']['delivery'] ?? 8;
    if (is_array($value)) {
        foreach ($defaults as $category => $fallback) $defaults[$category] = Club_Config_Number($value[$category] ?? $fallback, 1) ?: $fallback;
        return $defaults;
    }
    $total = Club_Config_Number($value, 1) ?: 8; $each = max(1, (int)floor($total / 4));
    $workers = ['follow' => $each, 'notice' => $each, 'announce' => $each, 'relay' => $each];
    foreach (['announce', 'relay', 'follow', 'notice'] as $category) if (array_sum($workers) < $total) $workers[$category]++;
    return $workers;
}

function Club_Config_Number($value, $min) {
    if (!is_int($value) && (!is_string($value) || !preg_match('/^-?[0-9]+$/D', $value))) return false;
    return (int)$value >= $min ? (int)$value : false;
}

function Club_Config_Proxy_Routes($traffic) {
    global $config;
    $scope = $config['node']['proxy-scope'] ?? 'all';
    $proxy = $config['node']['proxy'] ?? '';
    if ($proxy === '' || ($scope !== 'all' && $scope !== $traffic)) return [''];
    switch ($config['node']['proxy-mode'] ?? 'proxy-only') {
        case 'direct-first': return ['', $proxy];
        case 'proxy-first': return [$proxy, ''];
        default: return [$proxy];
    }
}

function Club_Config_Validate() {
    global $config; $fatal = []; $warn = [];
    if (!is_array($config)) return [['config.php did not define $config as an array'], []];
    foreach (['mysql', 'node', 'club', 'dns', 'default', 'worker', 'notice'] as $section)
        if (array_key_exists($section, $config) && !is_array($config[$section])) $fatal[] = 'config.'.$section.' must be an array';
    if ($fatal) return [$fatal, []];
    $authority = $config['base'] ?? null;
    if (!is_string($authority) || !preg_match('/^(\[[a-f0-9:.]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?$/iD', $authority, $m)
        || !($m[1][0] === '[' ? filter_var(substr($m[1], 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) : filter_var($m[1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
        || (isset($m[2]) && ((int)$m[2] < 1 || (int)$m[2] > 65535))) $fatal[] = 'config.base must contain an HTTPS host and optional port';
    else $config['base'] = strtolower($m[1]).(isset($m[2]) ? ':'.(int)$m[2] : '');
    foreach (['host', 'database', 'username', 'password'] as $key) if (!is_string($config['mysql'][$key] ?? null)) $fatal[] = 'config.mysql.'.$key.' must be a string';
    if (!is_string($tz = $config['node']['timezone'] ?? null) || !in_array($tz, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true))
        $fatal[] = 'config.node.timezone must be a valid timezone identifier';
    if (!is_array($names = $config['club']['suspended-names'] ?? null) || count(array_filter($names, 'is_string')) !== count($names)) $fatal[] = 'config.club.suspended-names must be a list of names';
    else $config['club']['suspended-names'] = array_map('strtolower', $names);
    foreach (['pdo_mysql', 'curl', 'openssl', 'json'] as $ext) if (!extension_loaded($ext)) $fatal[] = 'ext-'.$ext.' is required but not loaded';

    $node = (array)($config['node'] ?? []);
    foreach (['node' => 'inbox-verify', 'club' => 'open-registrations', 'notice' => 'enabled'] as $section => $key)
        if (array_key_exists($key, $config[$section] ?? []) && !is_bool($config[$section][$key])) $fatal[] = 'config.'.$section.'.'.$key.' must be boolean';
    if (!array_key_exists('proxy', $node)) $warn[] = 'config.node.proxy is missing, outbound requests use a direct connection';
    elseif (($proxy = $node['proxy']) !== '') {
        $userinfo = '(?:[a-z0-9._~!$&\'()*+,;=:-]|%[0-9a-f]{2})+';
        if (!is_string($proxy) || !preg_match('#\A(?:http|socks5)://(?:'.$userinfo.'@)?(\[[0-9a-f:.]+\]|[a-z0-9._-]+):([0-9]{1,5})/?\z#i', $proxy, $m)
            || (int)$m[2] < 1 || (int)$m[2] > 65535 || !($m[1][0] === '[' ? filter_var(substr($m[1], 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) : Club_Url_Host($m[1])))
            $fatal[] = 'config.node.proxy must be empty or an http:// or socks5:// proxy URL with a host and port';
        elseif (extension_loaded('curl') && !defined('CURLOPT_CONNECT_TO')) $fatal[] = 'config.node.proxy requires libcurl with CURLOPT_CONNECT_TO';
    }
    if (array_key_exists('proxy-scope', $node)) {
        if (!in_array($node['proxy-scope'], ['all', 'federation', 'doh'], true)) $fatal[] = 'config.node.proxy-scope must be all, federation or doh';
    } elseif (!empty($node['proxy'])) $warn[] = 'config.node.proxy-scope is missing, federation and DoH both use the configured proxy';
    if (array_key_exists('proxy-mode', $node)) {
        if (!in_array($node['proxy-mode'], ['direct-first', 'proxy-first', 'proxy-only'], true)) $fatal[] = 'config.node.proxy-mode must be direct-first, proxy-first or proxy-only';
    } elseif (!empty($node['proxy'])) $warn[] = 'config.node.proxy-mode is missing, traffic selected for the proxy uses the proxy only';

    if (($node['log-level'] ?? null) === false) $config['node']['log-level'] = 'silent';
    elseif (!is_string($level = $node['log-level'] ?? 'info') || !in_array(strtolower($level), ['silent', 'error', 'warning', 'info', 'debug'], true)) {
        $warn[] = 'config.node.log-level is not one of silent/error/warning/info/debug, falling back to info';
        $config['node']['log-level'] = 'info';
    }
    if (!is_string($lang = $node['language'] ?? 'en') || !Club_I18n_Match($lang)) {
        $warn[] = 'config.node.language is unknown, falling back to en'; $config['node']['language'] = 'en';
    }
    foreach (['node' => ['name', 'description'], 'default' => ['avatar', 'banner', 'summary', 'nickname']] as $section => $keys)
        foreach ($keys as $key) if (!is_string($config[$section][$key] ?? null)) { $warn[] = 'config.'.$section.'.'.$key.' must be a string, using empty text'; $config[$section][$key] = ''; }
    $map = $config['default']['infoname'] ?? [];
    if (!is_array($map) || count(array_filter($map, 'is_string')) !== count($map) || count(array_filter(array_keys($map), 'is_string')) !== count($map))
        $fatal[] = 'config.default.infoname must map tags to strings';
    else $config['default']['infoname'] = $map;
    if (!is_array($config['node']['maintainer'] ?? null)) $config['node']['maintainer'] = [];
    foreach (['name', 'email'] as $key) if (!is_string($config['node']['maintainer'][$key] ?? null)) {
        $warn[] = 'config.node.maintainer.'.$key.' must be a string, using empty text'; $config['node']['maintainer'][$key] = '';
    }
    if (!isset($config['club']['open-registrations'])) { $warn[] = 'config.club.open-registrations is missing, registrations closed'; $config['club']['open-registrations'] = false; }
    if (!is_array($delivery = $config['worker']['delivery'] ?? null)) $warn[] = 'config.worker.delivery should define follow/notice/announce/relay worker counts, falling back to the legacy split';
    else foreach (Club_Task_Category_List() as $category) if (Club_Config_Number($delivery[$category] ?? null, 1) === false)
        $warn[] = 'config.worker.delivery.'.$category.' must be at least 1, falling back to the default';
    $resolvers = $config['dns']['resolver'] ?? []; $config['dns']['resolver'] = [];
    if (!is_array($resolvers) || !$resolvers) $warn[] = 'config.dns.resolver is empty or not a list, using built-in resolvers';
    else foreach (array_values($resolvers) as $i => $resolver) {
        $url = is_array($resolver) ? Club_Endpoint_Normalize($resolver['url'] ?? null) : false;
        if (!$url || strpos($url, 'https://') !== 0) { $warn[] = 'config.dns.resolver['.$i.'].url must be an HTTPS URL, skipping resolver'; continue; }
        $ips = $resolver['ip'] ?? []; if (is_string($ips)) $ips = [$ips];
        if (!is_array($ips) || count(array_filter($ips, function ($ip) { return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP); })) !== count($ips)) {
            $warn[] = 'config.dns.resolver['.$i.'].ip must contain valid IP addresses, skipping resolver'; continue;
        }
        $config['dns']['resolver'][] = ['url' => $url, 'ip' => array_values(array_unique($ips))];
    }
    $config['dns']['resolver'] = Club_Resolver_List();
    foreach (['node' => ['log-retention' => [0,30]], 'worker' => ['probe' => [0,1]], 'dns' => ['timeout' => [1,5], 'connect-timeout' => [1,3]],
        'club' => ['relay-limit' => [0,512], 'create-limit' => [0,10]], 'notice' => ['limit' => [0,20], 'retention' => [0,30]]] as $section => $fields)
        foreach ($fields as $key => $range) {
            list($min, $default) = $range; $value = Club_Config_Number($config[$section][$key] ?? $default, $min);
            if ($value === false) $warn[] = 'config.'.$section.'.'.$key.' must be an integer >= '.$min.', using '.$default;
            $config[$section][$key] = $value === false ? $default : $value;
        }
    if ($config['club']['relay-limit'] === 0) $warn[] = 'config.club.relay-limit is 0, raw relay is disabled';
    if (!is_string($system = Club_Group_System()) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{1,29}$/D', $system)) {
        $warn[] = 'config.club.system-name is invalid, using system'; $config['club']['system-name'] = 'system';
    }
    if (!is_array($limits = $config['club']['limits'] ?? [])) $fatal[] = 'config.club.limits must be an array';
    foreach (array_keys(is_array($limits) ? $limits : []) as $club) {
        if (!is_array($limits[$club])) { $fatal[] = 'config.club.limits.'.$club.' must contain rules'; continue; }
        $rules = Club_Limit_Rules($club); $config['club']['limits'][$club] = [];
        foreach ($rules as $i => $rule) {
            if (!is_array($rule) || !is_string($rule['type'] ?? null)) { $fatal[] = 'config.club.limits.'.$club.'['.$i.'].type must be a string'; continue; }
            $rule['type'] = strtolower($rule['type']);
            if (!in_array($rule['type'], ['user', 'club', 'site', 'dupl'], true)) { $warn[] = 'config.club.limits.'.$club.'['.$i.'].type is unknown, using user'; $rule['type'] = 'user'; }
            if (Club_Config_Number($rule['hours'] ?? 0, 1) === false || Club_Config_Number($rule['limit'] ?? 0, 1) === false) {
                $warn[] = 'config.club.limits.'.$club.'['.$i.'] needs positive hours and limit, skipping rule'; continue;
            }
            $config['club']['limits'][$club][] = $rule;
        }
    }
    return [$fatal, $warn];
}

function Club_Worker_Slot($slot = null) {
    static $current = null;
    if (isset($slot)) $current = $slot;
    return $current;
}

function Club_Log_Console($level, $message, $context = []) {
    if (PHP_SAPI == 'cli') echo date('[Y-m-d H:i:s]').' '.$message.($context ? ' '.Club_Json_Encode($context) : ''), "\n";
    Club_Log_Event($level, $message, $context);
}

function Club_Log_Dir($dir = '') {
    if ($dir !== '' && !Club_Log_Dir()) return false;
    $path = APP_ROOT.'/logs'.($dir === '' ? '' : '/'.$dir);
    if (!is_dir($path)) {
        $mask = umask(0);
        try { if (!@mkdir($path, 0777, true) && !is_dir($path)) return false; } finally { umask($mask); }
        @chmod($path, 0777);
    } elseif ((fileperms($path) & 0777) !== 0777) @chmod($path, 0777);
    return $path;
}

function Club_Log_Event($level, $message, $context = [], $dir = 'event') {
    if (!Club_Log_Level($level) || !($path = Club_Log_Dir($dir))) return false;
    if ($ref = Club_Log_Ref()) $message = $ref.' '.$message;
    if ($context) $message .= ' '.Club_Json_Encode($context);
    $written = Club_Log_Put($path.'/'.date('Y-m-d').'.log', date('[Y-m-d H:i:s] ').strtoupper($level).' '.preg_replace('/\s+/', ' ', $message)."\n", FILE_APPEND);
    if ($written && Club_Worker_Slot() !== null && in_array($level, ['warning', 'error'], true)) Club_Stat($level === 'warning' ? 'log_warnings' : 'log_errors');
    return $written;
}

function Club_Log_Level($level) {
    global $config; static $rank = ['silent' => 0, 'error' => 1, 'warning' => 2, 'info' => 3, 'debug' => 4];
    $set = $config['node']['log-level'] ?? 'info';
    if ($set === false) $set = 'silent';
    elseif (!is_string($set) || !isset($rank[$set = strtolower($set)])) $set = 'info';
    return $rank[$set] >= $rank[$level];
}

function Club_Log_Name($dir, $parts) {
    if (!Club_Log_Level('error') || !Club_Log_Dir($dir)) return '';
    $name = date('Y-m-d_H-i-s');
    foreach ((array)$parts as $part) if (($part = Club_Log_Slug($part)) !== '') $name .= '_'.$part;
    $name = substr($name, 0, 160);
    while (!preg_match('//u', $name)) $name = substr($name, 0, -1);
    return $name.'-'.bin2hex(random_bytes(8));
}

function Club_Log_Put($file, $data, $flags = 0) {
    $new = !is_file($file);
    $mask = umask(0);
    try { if (@file_put_contents($file, $data, $flags | LOCK_EX) === false) return false; } finally { umask($mask); }
    if ($new || (fileperms($file) & 0777) !== 0666) @chmod($file, 0666);
    return true;
}

function Club_Log_Ref($ref = null) {
    static $current = '';
    if (isset($ref)) $current = $ref;
    return $current;
}

function Club_Log_Rotate($days) {
    if ($days < 1 || !is_dir($root = APP_ROOT.'/logs')) return;
    $expire = time() - $days * 86400;
    foreach (glob($root.'/*', GLOB_ONLYDIR) as $dir) foreach (glob($dir.'/*') as $file) if (is_file($file) && filemtime($file) < $expire) unlink($file);
}

function Club_Log_Shift($dirs = ['event', 'stat']) {
    foreach ($dirs as $dir) {
        if (!($path = Club_Log_Dir($dir))) { Club_Log_Console('warning', 'log shift skipped, cannot create directory', ['dir' => $dir]); continue; }
        $file = $path.'/'.date('Y-m-d').'.log';
        if (!is_file($file) || !filesize($file)) { Club_Log_Event('debug', 'log shift skipped, nothing to move', ['file' => $file]); continue; }
        for ($i = 1; is_file($file.sprintf('.%03d', $i)); $i++);
        error_clear_last();
        if (@rename($file, $target = $file.sprintf('.%03d', $i))) continue;
        Club_Log_Console('warning', 'log shift failed, still appending to the previous file', ['file' => $file, 'target' => $target, 'error' => error_get_last()['message'] ?? '']);
    }
}

function Club_Log_Slug($part) {
    $part = str_replace(['/', '\\'], 'Ⳇ', (string)$part);
    return preg_replace(['/\s+/', '/[\x00-\x1f\x7f*?\[\]]/'], ['_', ''], $part);
}

function Club_Log_Sync_Error() {
    static $last = '';
    if (!Club_Log_Level('error')) return;
    $file = APP_ROOT.'/logs/error/'.date('Y-m-d').'.log';
    if ($file === $last && is_file($file)) return;
    if (!Club_Log_Dir('error')) return;
    Club_Log_Put($file, '', FILE_APPEND);
    ini_set('error_log', $last = $file);
}

function Club_Log_Write($level, $dir, $name, $data, $ext = 'json') {
    if (!Club_Log_Level($level)) return false;
    if (is_array($name)) $name = Club_Log_Name($dir, $name);
    if ($name === '' || !($path = Club_Log_Dir($dir))) return false;
    return Club_Log_Put($path.'/'.$name.($ext === '' ? '' : '.'.$ext), is_string($data) ? $data : Club_Json_Encode($data));
}

function Club_Stat($key, $value = 1) {
    static $data = [];
    if (!isset($key)) { $out = $data; $data = []; return $out; }
    if (!isset($data[$key])) $data[$key] = 0;
    $data[$key] += $value;
    return $data[$key];
}

function Club_Stat_Flush($force = false, $window = 60, $heartbeat = 900) {
    static $opened = 0, $checked = 0, $seen = 0, $progress = 0, $jitter = null;
    static $pending = [], $pending_samples = [], $pending_gauges = [];
    $now = time(); $slot = Club_Worker_Slot();
    if (!isset($jitter)) $jitter = mt_rand(0, 20);
    if (!$opened) {
        $opened = $checked = $seen = $progress = $now;
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
            'endpoint_claim_races' => (int)($pending['endpoint_claim_races'] ?? 0), 'scheduler_db_ops' => (int)($pending['scheduler_db_ops'] ?? 0)], 'stat');
        $pending = []; $pending_samples = []; $pending_gauges = [];
        return true;
    }
    $elapsed = max(1, $now - $opened); $opened = $seen = $progress = $now;
    $attempts = (int)($pending['endpoint_claim_attempts'] ?? 0);
    $summary = ['slot' => $slot, 'pid' => getmypid(), 'window_s' => $elapsed,
        'endpoint_claim_attempts' => $attempts,
        'endpoint_claim_hit' => $attempts ? round(1 - (($pending['endpoint_misses'] ?? 0) + ($pending['endpoint_claim_races'] ?? 0)) / $attempts, 3) : 0,
        'scheduler_db_ops' => (int)($pending['scheduler_db_ops'] ?? 0)];
    foreach ($pending_samples as $key => $values) $summary[$key] = Club_Stat_Summary($values);
    foreach ($pending_gauges as $key => $value) $summary[$key] = $value;
    foreach ($pending as $key => $value) if (!isset($summary[$key]) && $key != 'endpoint_misses') $summary[$key] = $value;
    $summary['busy_ratio'] = $elapsed ? round(max(0, min(1, 1 - ($pending['idle_ms'] ?? 0) / ($elapsed * 1000))), 3) : 0;
    Club_Log_Event('info', 'worker summary', $summary, 'stat');
    $pending = []; $pending_samples = []; $pending_gauges = [];
    return true;
}

function Club_Stat_Max($key, $value = 0) {
    static $data = [];
    if (!isset($key)) { $out = $data; $data = []; return $out; }
    if (!isset($data[$key]) || $value > $data[$key]) $data[$key] = $value;
    return $data[$key];
}

function Club_Stat_Request() {
    $counters = Club_Stat(null); $samples = Club_Stat_Sample(null);
    if (!$counters) return false;
    $dns = 0;
    foreach ($counters as $key => $value) if (strpos($key, 'dns_') === 0) $dns += $value;
    if (!$dns) return false;
    foreach ($samples as $key => $values) $counters[$key] = Club_Stat_Summary($values);
    Club_Log_Event('info', 'request summary', ['sapi' => PHP_SAPI, 'pid' => getmypid()] + $counters, 'stat');
    return true;
}

function Club_Stat_Sample($key, $ms = null) {
    static $data = [];
    if (!isset($key)) { $out = $data; $data = []; return $out; }
    if (!isset($data[$key])) $data[$key] = [];
    if (count($data[$key]) < 1000) $data[$key][] = $ms;
    return true;
}

function Club_Stat_Summary($samples) {
    sort($samples); $count = count($samples); $out = [];
    foreach (['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99] as $name => $rank) $out[$name] = round($samples[min($count - 1, (int)ceil($rank * $count) - 1)], 1);
    $out['max'] = round($samples[$count - 1], 1);
    return $out;
}

function Club_Monitor_Count($key, $value = 1) {
    static $data = [];
    if (!isset($key)) { $out = $data; $data = []; return $out; }
    if (!isset($data[$key])) $data[$key] = 0;
    $data[$key] += $value;
    return $data[$key];
}

function Club_Monitor_Snapshot($reset = false) {
    global $db; static $last = 0;
    if ($reset) { $last = 0; return false; }
    $now = time();
    if (!$last) { $last = $now; return false; }
    $window = $now - $last;
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
    $last = $now;
    return true;
}

function Club_Template_Escape($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function Club_Template_Link($url) {
    return preg_match('#^https?://#i', (string)$url) ? Club_Template_Escape($url) : '';
}

function Club_Template_NameTag($club, $str, $tag) {
    global $config;
    $str = str_replace(array_keys($tag), array_values($tag), $str);
    return str_replace([':club_name:', ':local_domain:'], [$club, $config['base']], $str);
}

function Club_Template_Render($name, $vars = []) {
    return require(APP_ROOT.'/app/template/'.$name.'.php');
}

function Club_I18n($key, $locale, $vars = []) {
    global $config; static $cache = [];
    foreach (array_unique([$locale, Club_I18n_Locale(null), 'en']) as $try) {
        if (!isset($cache[$try])) {
            $file = APP_ROOT.'/app/template/i18n/'.$try.'.php';
            $cache[$try] = is_file($file) ? require($file) : [];
        }
        if (isset($cache[$try][$key])) {
            $text = $cache[$try][$key];
            foreach ($vars as $k => $v) $text = str_replace(':'.$k.':', $v, $text);
            return $text;
        }
    } return '';
}

function Club_I18n_Detect($object) {
    if (empty($object['contentMap']) || !is_array($object['contentMap'])) return false;
    foreach (array_keys($object['contentMap']) as $lang) if ($match = Club_I18n_Match($lang)) return $match;
    return false;
}

function Club_I18n_Locale($lang) {
    global $config;
    return Club_I18n_Match($lang) ?: (Club_I18n_Match($config['node']['language'] ?? 'en') ?: 'en');
}

function Club_I18n_Match($lang) {
    static $map = [
        'zh' => 'zh-CN', 'zh-hans' => 'zh-CN', 'zh-cn' => 'zh-CN', 'zh-sg' => 'zh-CN',
        'zh-hant' => 'zh-TW', 'zh-hant-tw' => 'zh-TW', 'zh-tw' => 'zh-TW',
        'zh-hant-hk' => 'zh-HK', 'zh-hk' => 'zh-HK', 'zh-mo' => 'zh-HK',
        'yue' => 'zh-HK', 'zh-yue' => 'zh-HK',
        'ja' => 'ja', 'en' => 'en'
    ];
    $lang = strtolower(str_replace('_', '-', trim((string)$lang)));
    return $map[$lang] ?? $map[explode('-', $lang)[0]] ?? false;
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
    foreach ([['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20]] as $range) if (Club_IP_Matches($packed, $range[0], $range[1])) return false;
    return true;
}

function Club_Url_Absolute($url, $base) {
    if (!preg_match('~^(https?://[^/?#]+)([^?#]*)(\?[^#]*)?~i', $base, $b) || !is_string($url)) return '';
    if (substr($url, 0, 2) === '//') $url = substr($b[1], 0, strpos($b[1], ':') + 1).$url;
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
        if (!preg_match('~^(https?://[^/?#]+)([^?#]*)([?#].*)?$~iD', $url, $m)) return '';
        $root = $m[1]; $path = $m[2]; $suffix = $m[3] ?? '';
    } else {
        $root = $b[1]; $path = substr($url, 0, strcspn($url, '?#')); $suffix = substr($url, strlen($path));
        if ($path === '') return $root.$b[2].(substr($suffix, 0, 1) === '?' ? '' : ($b[3] ?? '')).$suffix;
        if ($path[0] !== '/') $path = preg_replace('~[^/]*$~D', '', $b[2] ?: '/').$path;
    }
    if ($path === '') return $root.$suffix;
    $out = [];
    foreach (explode('/', substr($path, 1)) as $part) {
        if ($part === '..') array_pop($out);
        elseif ($part !== '.') $out[] = $part;
    }
    if (preg_match('~/\.\.?$~D', $path)) $out[] = '';
    return $root.'/'.implode('/', $out).$suffix;
}

function Club_Url_Host($input) {
    $host = strtolower(rtrim(trim((string)$input, " \t\n\r\0\x0B[]"), '.'));
    if (filter_var($host, FILTER_VALIDATE_IP)) return inet_ntop(inet_pton($host));
    if (strlen($host) > 253 || !preg_match('/^[a-z0-9]([a-z0-9_-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9_-]{0,61}[a-z0-9])?)*$/D', $host)) return false;
    return $host;
}

function Club_Url_Public($url) {
    if (!is_string($url) || preg_match('~[\x00-\x20\x7f\\\\]~', $url) || !preg_match('~^(https?://[^/?#]+)~i', $url, $m) || !Club_Endpoint_Normalize($m[1].'/')) return false;
    $parts = parse_url((string)$url);
    if (empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'])) return false;
    $host = trim($parts['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    elseif (!($ips = Club_Resolver_Get($host))) return null;
    $public = [];
    foreach ($ips as $ip) if (Club_IP_Public($ip)) $public[] = $ip;
    $public = array_values(array_unique($public));
    if (!$public) return false;
    if (count($public) < count($ips)) Club_Log_Event('debug', 'dropped non-public addresses', ['host' => $host, 'kept' => implode(',', $public), 'dropped' => implode(',', array_diff($ips, $public))]);
    return $public;
}

function Club_Json_Encode($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
