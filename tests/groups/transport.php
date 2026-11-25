<?php require_once(TEST_ROOT.'/services.php');

function t_transport_run() {
    global $config;
    t_group('transport / TLS response boundaries'); $dir = t_workspace('transport');
    t_tls_keys($dir);
    $process = t_php_process([TEST_ROOT.'/fixtures/http-service.php', $dir], $dir);
    try {
        t_wait_ready($process, function () use ($dir) { return is_file($dir.'/port') && (int)file_get_contents($dir.'/port') > 0; });
        $origin = 'https://transport.example:'.trim(file_get_contents($dir.'/port')); $handle = curl_init(); $pin = null;
        $options = [CURLOPT_CAINFO => $dir.'/ca.pem', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_TIMEOUT => 5];
        $request = function ($path, $body = null, $traffic = 'federation') use ($handle, $origin, $options, &$pin) {
            return Club_HTTP_Request($handle, $origin.$path, [], $options, $body, ['127.0.0.1'], $pin, $traffic);
        };
        foreach (['/ordinary', '/a/%2E/b?x=1', '/a/../b?'] as $path) {
            $r = $request($path);
            t_is([$r['status'], $r['response'], $r['error']], [200, $path, false], 'TLS request target '.$path);
        }
        $r = $request('/early'); t_is([$r['status'], isset($r['headers']['link'])], [200, false], 'interim headers yield to origin headers');
        $r = $request('/redirect'); t_is([$r['status'], Club_HTTP_Header($r['headers'], 'Location')], [302, '?page=2'], 'complete redirect location is available');
        $r = $request('/large'); t_ok($r['curl_error'] && $r['response'] === false && $r['body_bytes'] > 1048576, 'oversized document is discarded');
        $r = $request('/doh', null, 'doh'); t_ok($r['curl_error'] && $r['body_bytes'] > 65536, 'DoH uses its smaller budget');
        foreach (['/fields','/headers'] as $path) { $r = $request($path); t_is([$r['status'], $r['curl_error']], [0, true], 'incomplete headers '.$path); }
        $r = $request('/large', '{}');
        t_is([ActivityPub_Push_State($r['status']), $r['fallback']], ['ok', false], 'final acceptance survives a truncated delivery response');
        $r = $request('/ordinary'); t_is($r['response'], '/ordinary', 'reused handle gets fresh budgets');
        $node = $config['node']; $config['node']['proxy'] = 'http://127.0.0.1:1'; $config['node']['proxy-mode'] = 'direct-first';
        try {
            $calls = 0; $check = function () use (&$calls) { $calls++; return false; };
            $r = Club_HTTP_Request($handle, $origin.'/denied', [], $options, '{}', ['127.0.0.1'], $pin, 'federation', $check);
            t_is([$r['status'], $r['lease_lost'] ?? false, $calls], [403, true, 1], 'alternate route requires fresh authorization');
            $r = Club_HTTP_Request($handle, $origin.'/unacknowledged', [], $options, '{}', ['127.0.0.1'], $pin, 'federation', $check);
            t_is([$r['status'], $r['curl_error'], $r['fallback'], $calls], [0, true, false, 1], 'a missing delivery receipt does not trigger a second send');
        } finally { $config['node'] = $node; }
        $requests = file($dir.'/requests', FILE_IGNORE_NEW_LINES); t_is(count(array_keys($requests, 'POST /large', true)), 1, 'accepted POST arrived once');
        t_is(count(array_keys($requests, 'POST /unacknowledged', true)), 1, 'unacknowledged POST arrived once');
        unset($options[CURLOPT_CAINFO]);
        $r = Club_HTTP_Request($handle, $origin.'/ordinary', [], $options, null, ['127.0.0.1'], $pin);
        t_ok($r['curl_error'] && !$r['status'], 'untrusted fixture certificate fails verification');
    } finally { proc_terminate($process); proc_close($process); }
}

t_transport_run();
