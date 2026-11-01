<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Transport\HostResolver;

/**
 * POST /_gaze: forwards a batch from a page that cannot reach the app itself (a browser on
 * another machine, a phone, a page on a non-allowlisted origin) to the app's POST /v1/batch.
 *
 * Registered only while Gaze is enabled and APP_ENV is local, and checked again per request (a
 * cached route outlives a changed .env). Accepts application/json or text/plain (sendBeacon), up
 * to relay.max_bytes, and only a v1 batch with entries. The app trusts what arrives here like a
 * local process, so other sites must not get in: an Origin whose host is not this request's
 * (another site, or "null" from a sandboxed frame) is refused, and so is a Host this machine did
 * not name (see hostAllowed()). No incoming header is forwarded: the upstream request carries
 * Content-Type, Accept and User-Agent, plus the app token (GAZE_TOKEN) when the app is on a
 * non-loopback host, exactly like the PHP client's own requests.
 */
final class RelayController
{
    public function __invoke(Request $request, GazeManager $gaze): Response
    {
        if (!$gaze->enabled() || !$gaze->app->isLocal() || !GazeManager::bool($gaze->config('relay.enabled', true))) {
            throw new NotFoundHttpException();
        }
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        if ($type !== 'application/json' && $type !== 'text/plain') {
            return self::problem(415, 'Send the batch as application/json or text/plain.');
        }
        $host = strtolower(rtrim($request->getHost(), '.'));
        if (!self::hostAllowed($host, $gaze)) {
            return self::problem(403, "Requests for host {$host} are not relayed. Add it to GAZE_RELAY_HOSTS if it is yours.");
        }
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '' && strcasecmp((string) parse_url($origin, PHP_URL_HOST), $host) !== 0) {
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

    /**
     * Hosts no other site can get a browser to send: localhost, *.localhost, *.test, IP literals
     * (a phone on the LAN), APP_URL's host and gaze.relay.hosts. A DNS-rebinding page sends its
     * own domain, which now resolves to this machine, and Origin matches it, so only the Host
     * check stops it.
     */
    private static function hostAllowed(string $host, GazeManager $gaze): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        $extra = $gaze->config('relay.hosts', []);
        $known = is_string($extra) ? explode(',', $extra) : (array) $extra;
        $known[] = (string) parse_url((string) $gaze->app['config']->get('app.url', ''), PHP_URL_HOST);
        foreach ($known as $name) {
            $name = rtrim(trim((string) $name), '.');
            if ($name !== '' && strcasecmp($name, $host) === 0) {
                return true;
            }
        }

        return false;
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
