<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Transport\HostResolver;

/**
 * POST /_gaze: forwards a batch from a page that cannot reach the app itself (a browser on
 * another machine, a phone, a page on a non-allowlisted origin) to the app's POST /v1/batch.
 *
 * Registered only while Gaze is enabled and APP_ENV is local. Accepts application/json or
 * text/plain (sendBeacon), up to relay.max_bytes, and only a v1 batch with entries. Same-origin
 * only (a cross-site Origin header is refused). No incoming header is forwarded: the upstream
 * request carries Content-Type, Accept and User-Agent, plus the app token (GAZE_TOKEN) when the
 * app is on a non-loopback host, exactly like the PHP client's own requests.
 */
final class RelayController
{
    public function __invoke(Request $request, GazeManager $gaze): Response
    {
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        if ($type !== 'application/json' && $type !== 'text/plain') {
            return self::problem(415, 'Send the batch as application/json or text/plain.');
        }
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '' && $origin !== 'null' && strcasecmp((string) parse_url($origin, PHP_URL_HOST), $request->getHost()) !== 0) {
            return self::problem(403, 'Cross-origin requests are not relayed.');
        }
        $max = max(1024, (int) $gaze->config('relay.max_bytes', 1048576));
        $length = $request->headers->get('Content-Length');
        if (is_numeric($length) && (int) $length > $max) {
            return self::problem(413, "The batch is larger than {$max} bytes.");
        }
        $body = (string) $request->getContent();
        if (strlen($body) > $max) {
            return self::problem(413, "The batch is larger than {$max} bytes.");
        }
        $batch = json_decode($body, true);
        if (!is_array($batch) || ($batch['v'] ?? null) !== 1 || !is_array($batch['entries'] ?? null) || $batch['entries'] === []
            || !is_array($batch['client'] ?? null) || !is_string($batch['session'] ?? null)) {
            return self::problem(400, 'Not a protocol v1 batch (v, client, session, entries).');
        }

        $config = Gaze::config();
        $port = (int) $config['port'];
        $host = HostResolver::resolve($port);
        $token = is_string($config['token'] ?? null) ? $config['token'] : null;
        $h = curl_init(HostResolver::baseUrl($host, $port) . '/v1/batch');
        curl_setopt_array($h, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => Client::headersFor($host, $token),
            CURLOPT_CONNECTTIMEOUT_MS => max(100, (int) ($config['connect_timeout_ms'] ?? 100)) + 200,
            CURLOPT_TIMEOUT_MS => max(500, (int) ($config['timeout_ms'] ?? 2000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $answer = curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $error = curl_error($h);
        if ($answer === false || $status === 0) {
            return self::problem(502, 'The Gaze app is not reachable' . ($error !== '' ? ': ' . $error : '.'));
        }

        return new Response((string) $answer, $status, [
            'Content-Type' => $status >= 400 ? 'application/problem+json' : 'application/json',
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function problem(int $status, string $detail): Response
    {
        return new Response((string) json_encode([
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
        ], JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
    }
}
