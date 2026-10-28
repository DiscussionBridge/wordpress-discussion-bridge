<?php
declare(strict_types=1);

test('source move independently verified TLS loopback transports native MODEL page with canonical host no credentials and whole body proof', function (): void {
    expect(extension_loaded('curl') && extension_loaded('openssl') && function_exists('proc_open'), 'TLS fixture runtime missing');
    $directory = dirname(__DIR__) . '/.tmp/source-tls-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0700, true));
    $configuration = "[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=server\n[dn]\nCN=wordpress.example\n[server]\nsubjectAltName=DNS:wordpress.example\nbasicConstraints=critical,CA:TRUE\nkeyUsage=digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n";
    file_put_contents($directory . '/openssl.cnf', $configuration);
    $options = ['config' => $directory . '/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = openssl_pkey_new($options); expect($key !== false);
    $csr = openssl_csr_new(['commonName' => 'wordpress.example'], $key, $options); expect($csr !== false);
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions' => 'server']); expect($certificate !== false);
    expect(openssl_x509_export($certificate, $certificate_pem) && openssl_pkey_export($key, $private_pem, null, $options));
    file_put_contents($directory . '/ca.pem', $certificate_pem); file_put_contents($directory . '/server.pem', $certificate_pem . $private_pem);
    $server = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__ . '/fixtures/source-loopback-server.php', $directory],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    expect(is_resource($server)); fclose($pipes[0]);
    try {
        for ($attempt = 0; $attempt < 50 && !is_file($directory . '/ready.json'); $attempt++) usleep(100000);
        expect(is_file($directory . '/ready.json'), 'loopback server did not start');
        $ready = json_decode(file_get_contents($directory . '/ready.json')); $port = $ready->port;
        $old = source_move_model(false, false, null, 'https://wordpress.example:' . $port); source_theme_render(); source_finalize_mock($old);
        $url = home_url('/moved-source/');
        $GLOBALS['dbt']['responses'][$url] = function ($target, $args) use ($directory, $port): array {
            expect($args['redirection'] === 0 && $args['cookies'] === [] && !isset($args['headers']['Authorization'], $args['headers']['X-DiscussionBridge-Secret']));
            file_put_contents($directory . '/page.html', '<html><body>' . source_theme_render() . '</body></html>');
            $curl = curl_init($target); $headers = [];
            $request_headers = []; foreach ($args['headers'] as $key => $value) $request_headers[] = $key . ': ' . $value;
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $directory . '/ca.pem', CURLOPT_RESOLVE => ['wordpress.example:' . $port . ':127.0.0.1'],
                CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => $request_headers,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))] = trim($value); } return strlen($line);
                }]);
            $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
            expect(is_string($body) && $status === 200, 'TLS fixture request failed: ' . $error);
            return dbt_response($status, $body, $headers['content-type'] ?? '');
        };
        $result = \DiscussionBridge\WordPress\FromSynchronizer::serialized(fn($guard) => \DiscussionBridge\WordPress\CmsSourcePublisher::step(1, $guard));
        expect($result === 'healthy', is_wp_error($result) ? $result->get_error_code() : (string) $result);
        $request = file_get_contents($directory . '/request.txt');
        expect(str_starts_with($request, 'GET /moved-source/ HTTP/1.1') && str_contains($request, 'Host: wordpress.example:' . $port));
        expect(preg_match('/\r\n(?:Cookie|Authorization|X-DiscussionBridge-Secret|X-DiscussionBridge-Connection):/i', $request) === 0);
        $state = (new \DiscussionBridge\WordPress\CmsJournal(1))->read();
        expect($state->source_move_history[0]->verification->verification_profile === \DiscussionBridge\WordPress\CmsSourceOwnership::PROFILE);
        expect($GLOBALS['dbt']['source_theme_renders'] === 2 && $state->receipt->resource_id === $old->receipt->resource_id);
    } finally {
        $status = proc_get_status($server); if ($status['running']) proc_terminate($server);
        foreach ([1, 2] as $pipe) fclose($pipes[$pipe]); proc_close($server);
    }
});
