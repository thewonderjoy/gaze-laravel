<?php

// Fake Gaze app for the Laravel tests: `php -S 127.0.0.1:<port> tests/Fixtures/fake-app.php`
// (adapted from packages/php/tests/Fixtures/fake-app.php).
// Env GAZE_FAKE_DIR: directory with control.json (behaviour) and requests.ndjson (every request).
//
// control.json keys:
//   hello_status  int   status for GET /v1/hello (default 200)
//   watchers      map   hello "watchers" (remote watcher switches)
//   redact        list  hello "redact" (extra key patterns)
//   batch_status  int   status for POST /v1/batch (default 202)

$dir = getenv('GAZE_FAKE_DIR') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url($uri, PHP_URL_PATH);
$control = json_decode((string) @file_get_contents($dir . '/control.json'), true) ?: [];
$body = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

file_put_contents($dir . '/requests.ndjson', json_encode([
    't' => microtime(true),
    'method' => $method,
    'path' => $path,
    'headers' => $headers,
    'body' => $body,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');

if ($method === 'GET' && $path === '/v1/hello') {
    $status = (int) ($control['hello_status'] ?? 200);
    http_response_code($status);
    echo $status !== 200 ? '{}' : json_encode([
        'v' => 1,
        'server' => ['name' => 'fake-app', 'version' => '0.0.0', 'instance' => 'instance-1'],
        'protocol' => ['min' => 1, 'max' => 1],
        'features' => ['batch', 'locks.wait', 'values.v1', 'ops.append', 'watchers.remote'],
        'limits' => ['max_body_bytes' => 67108864, 'max_entries' => 5000, 'max_value_nodes' => 5000, 'max_string_chars' => 16384],
        'watchers' => (object) ($control['watchers'] ?? []),
        'redact' => $control['redact'] ?? [],
        'paused' => false,
    ]);

    return true;
}

if ($method === 'POST' && $path === '/v1/batch') {
    $status = (int) ($control['batch_status'] ?? 202);
    http_response_code($status);
    $decoded = json_decode($body, true);
    echo $status === 202
        ? json_encode(['accepted' => is_array($decoded) ? count($decoded['entries'] ?? []) : 0])
        : json_encode(['type' => 'about:blank', 'title' => 'fake error', 'status' => $status]);

    return true;
}

http_response_code(404);
echo '{"type":"about:blank","status":404}';

return true;
