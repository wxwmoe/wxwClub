<?php

function t_guard_database($name) {
    if (!preg_match('/^[a-z0-9_-]{1,64}$/iD', $name) || !preg_match('/(?:^|[_-])test(?:$|[_-])/iD', $name)) {
        fwrite(STDERR, "Refusing to reset a database without a test name segment\n"); exit(1);
    }
}

function t_workspace($name) {
    $root = rtrim(getenv('CLUB_TEST_TMPDIR') ?: sys_get_temp_dir(), '/\\').'/wxwclub-'.$name.'-'.bin2hex(random_bytes(8));
    if (!mkdir($root, 0700)) throw new RuntimeException('Could not create test directory');
    register_shutdown_function(function () use ($root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($root);
    });
    return $root;
}

function t_php_process($args, $dir) {
    $command = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY], $args)));
    $process = proc_open((DIRECTORY_SEPARATOR === '/' ? 'exec ' : '').$command, [1 => ['file', $dir.'/process.log', 'a'], 2 => ['file', $dir.'/process.log', 'a']], $pipes, $dir);
    if (!is_resource($process)) throw new RuntimeException('Could not start PHP service');
    return $process;
}

function t_tls_keys($dir) {
    $settings = $dir.'/openssl.cnf';
    file_put_contents($settings, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=keyCertSign\n[server]\n".
        "basicConstraints=critical,CA:FALSE\nsubjectAltName=DNS:transport.example,DNS:alias.example\nextendedKeyUsage=serverAuth\n");
    $opts = ['config' => $settings, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $ca = openssl_pkey_new($opts); $key = openssl_pkey_new($opts);
    $csr = openssl_csr_new(['commonName' => 'wxwClub test CA'], $ca, $opts);
    $root = openssl_csr_sign($csr, null, $ca, 1, $opts + ['x509_extensions' => 'ca']);
    $csr = openssl_csr_new(['commonName' => 'transport.example'], $key, $opts);
    $cert = openssl_csr_sign($csr, $root, $ca, 1, $opts + ['x509_extensions' => 'server'], 2);
    openssl_x509_export_to_file($root, $dir.'/ca.pem'); openssl_x509_export_to_file($cert, $dir.'/server.pem'); openssl_pkey_export_to_file($key, $dir.'/key.pem', null, $opts);
}

function t_wait_ready($process, $check) {
    $until = microtime(true) + 10;
    do {
        clearstatcache();
        if ($check()) return;
        if (!proc_get_status($process)['running']) break;
        usleep(20000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Test service did not become ready');
}
