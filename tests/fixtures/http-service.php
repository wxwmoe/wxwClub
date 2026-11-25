<?php

$dir = $argv[1]; $tunnel = ($argv[2] ?? '') === 'tunnel';
$context = stream_context_create(['ssl' => ['local_cert' => $dir.'/server.pem', 'local_pk' => $dir.'/key.pem', 'verify_peer' => false]]);
$socket = stream_socket_server(($tunnel ? 'tcp' : 'tls').'://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$socket) exit(1);
file_put_contents($dir.'/port', substr(strrchr(stream_socket_get_name($socket, false), ':'), 1));
while ($client = @stream_socket_accept($socket, 30)) {
    stream_set_timeout($client, 3);
    if ($tunnel) {
        $connect = trim((string)fgets($client));
        while (($header = fgets($client)) !== false && trim($header) !== '');
        file_put_contents($dir.'/tunnels', $connect."\n", FILE_APPEND);
        if ($connect !== 'CONNECT 1.1.1.1:443 HTTP/1.1') { fclose($client); continue; }
        fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) { fclose($client); continue; }
    }
    $line = trim((string)fgets($client)); $bytes = 0; $host = ''; $signature = ''; $date = '';
    while (($header = fgets($client)) !== false && trim($header) !== '') {
        if (stripos($header, 'Content-Length:') === 0) $bytes = (int)trim(substr($header, 15));
        if (stripos($header, 'Host:') === 0) $host = trim(substr($header, 5));
        if (stripos($header, 'Signature:') === 0) $signature = trim(substr($header, 10));
        if (stripos($header, 'Date:') === 0) $date = trim(substr($header, 5));
    }
    if ($bytes > 0) stream_get_contents($client, $bytes);
    list($method, $path) = explode(' ', $line, 3); file_put_contents($dir.'/requests', $method.' '.$path."\n", FILE_APPEND);
    if ($path === '/unacknowledged') { fclose($client); continue; }
    if ($path === '/early') fwrite($client, "HTTP/1.1 103 Early Hints\r\nLink: </style.css>\r\n\r\n");
    $status = $path === '/redirect' ? '302 Found' : ($path === '/denied' ? '403 Forbidden' : '200 OK');
    $body = $path === '/large' ? str_repeat('a', 1048577) : ($path === '/doh' ? str_repeat('a', 65537) : $path);
    $headers = $path === '/redirect' ? "Location: ?page=2\r\n" : '';
    if ($tunnel && is_file($dir.'/replies.json')) {
        $replies = json_decode(file_get_contents($dir.'/replies.json'), true);
        list($status, $headers, $body) = $replies[$host.$path] ?? ['404 Not Found', '', ''];
        file_put_contents($dir.'/signed', json_encode([$host, $path, $signature, $date])."\n", FILE_APPEND);
    }
    if ($path === '/fields') for ($i = 0; $i < 129; $i++) $headers .= 'X-Sample-'.$i.": a\r\n";
    if ($path === '/headers') $headers .= 'X-Sample: '.str_repeat('a', 65536)."\r\n";
    @fwrite($client, 'HTTP/1.1 '.$status."\r\n".$headers.'Content-Length: '.strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    fclose($client);
}
