<?php
declare(strict_types=1);
// Isolated one-request TLS fixture. Serves a native-MODEL page, never claims installed WordPress proof.
$directory = $argv[1];
$context = stream_context_create(['ssl' => ['local_cert' => $directory . '/server.pem', 'verify_peer' => false]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) exit(2);
$address = stream_socket_get_name($server, false); $port = (int) substr($address, strrpos($address, ':') + 1);
file_put_contents($directory . '/ready.json', json_encode(['port' => $port]));
$client = stream_socket_accept($server, 15); if ($client === false) { fclose($server); exit(3); }
stream_set_timeout($client, 8);
if (stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) { fclose($client); fclose($server); exit(4); }
$request = ''; while (($line = fgets($client)) !== false) { $request .= $line; if ($line === "\r\n") break; }
file_put_contents($directory . '/request.txt', $request);
$body = file_get_contents($directory . '/page.html');
$response = "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
while ($response !== '') { $written = fwrite($client, $response); if ($written === false || $written === 0) exit(5); $response = substr($response, $written); }
fclose($client); fclose($server);
