<?php require('class/curl.php');

function ActivityPub_GET($url, $club) {
    $date = gmdate('D, d M Y H:i:s T');
    
    $curl = ActivityPub_CURL($date);
    $curl->setHeader('Signature', ActivityPub_Signature($url, $club, $date));
    $curl->get($url);
    
    //print_r($curl->responseHeaders);
    return $curl->error ? false : $curl->response;
}

function ActivityPub_POST($url, $club, $jsonld) {
    $date = gmdate('D, d M Y H:i:s T');
	$digest = base64_encode(hash('sha256', $jsonld, 1));
	
	$curl = ActivityPub_CURL($date);
    $curl->setHeader('Signature', ActivityPub_Signature($url, $club, $date, $digest));
    $curl->setHeader('Digest', 'SHA-256='.$digest);
    $curl->post($url, $jsonld);
    
    return $curl->error ? false : $curl->response;
}

function ActivityPub_CURL($date) {
    global $ver, $base;
    $curl = new Curl();
	$curl->setTimeout(100);
	$curl->setMaximumRedirects(3);
    $curl->setUserAgent('wxwClub/'.$ver.'; +'.$base.'/; ActivityPub');
    $curl->setHeader('Accept', 'application/activity+json');
    $curl->setHeader('Content-Type', 'application/activity+json');
    $curl->setHeader('Date', $date);
    return $curl;
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

function Club_Exist($club) {
    global $db, $config;
    if (strlen($club) <= 30 && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]+$/u', $club)) {
        $pdo = $db->prepare('select `cid` from `clubs` where `name` = :name'); $pdo->execute([':name' => $club]);
        return $pdo->fetch(PDO::FETCH_ASSOC) ? true : ($config['openRegistrations'] ? Club_Create($club) : false);
    } return false;
}

function Club_Create($club) {
    global $db, $config;
    if (!in_array(strtolower($club), $config['nodeSuspendedName'])) {
        $key = openssl_pkey_new([
    		'digest_alg' => 'sha512',
    		'private_key_bits' => 2048,
    		'private_key_type' => OPENSSL_KEYTYPE_RSA
    	]); openssl_pkey_export($key, $priv_key);
        $detail = openssl_pkey_get_details($key);
        $pdo = $db->prepare('insert into `clubs`(`name`,`public_key`,`private_key`) values(:name, :public, :private)');
        return (bool)$pdo->execute([':name' => $club, ':public' => substr($detail['key'], 0, -1), ':private' => substr($priv_key, 0, -1)]);
    } return false;
}

function Club_Get_Actor($actor) {
    global $db; $pdo = $db->prepare('select `uid`,`name`,`inbox` from `users` where `actor` = :actor');
    $pdo->execute([':actor' => $actor]);
    if ($pdo = $pdo->fetch(PDO::FETCH_ASSOC)) {
        $uid = $pdo['uid'];
        $name = $pdo['name'];
        $inbox = $pdo['inbox'];
    } else {
        $jsonld = json_decode(ActivityPub_GET($actor, $club), 1);
        if ($jsonld['id'] == $actor) {
            $inbox = $jsonld['inbox'];
            $shared_inbox = $jsonld['endpoints']['sharedInbox'] ?: $jsonld['inbox'];
            $name = $jsonld['preferredUsername'].'@'.parse_url($jsonld['id'], PHP_URL_HOST);
            $pdo = $db->prepare('insert into `users`(`name`,`actor`,`inbox`,`public_key`,`shared_inbox`) values (:name, :actor, :inbox, :public_key, :shared_inbox)');
            $pdo->execute([
                ':name' => $name, ':actor' => $jsonld['id'], ':inbox' => $jsonld['inbox'],
                ':public_key' => $jsonld['publicKey']['publicKeyPem'], ':shared_inbox' => $shared_inbox
            ]);
            $pdo = $db->prepare('select `uid` from `users` where `name` = :name');
            $pdo->execute([':name' => $name]);
            $uid = $pdo->fetch(PDO::FETCH_ASSOC)['uid'];
        } return false;
    } return ['uid' => $uid, 'name' => $name, 'inbox' => $inbox];
}

function json_output($data, $format = 0, $status = 200) {
    switch ($format) {
        case 1: $format = 'jrd+json'; break;
        case 2: $format = 'activity+json'; break;
        default: $format = 'json'; break;
    } header('Content-type: application/'.$format.'; charset=utf-8');
    
    if ($status != 200) {
        http_response_code($status);
        $data = array_merge(['code' => $status], $data);
    } echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
