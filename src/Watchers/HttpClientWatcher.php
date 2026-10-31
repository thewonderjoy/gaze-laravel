<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\AppOrigin;
use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * Outgoing HTTP client calls as ONE entry each (kind http): added when the request is sent
 * (pending), updated in place with the response, the duration and the status, or with the
 * error of a ConnectionFailed. laravel-ray sent two unrelated rows and no failures.
 * Authorization/cookie headers are masked; bodies are cut at 64 KiB.
 */
final class HttpClientWatcher extends Watcher
{
    private const MAX_BODY = 65536;

    /** @var \WeakMap<object, array{token: string, id: string|null, hr: int, method: string, url: string, origin: array<string, mixed>|null, request: array<string, mixed>}> */
    private \WeakMap $pending;

    /** @var array<string, list<\WeakReference<object>>> method+url => requests in flight (ConnectionFailed pairing) */
    private array $byUrl = [];

    public function __construct(GazeManager $gaze)
    {
        parent::__construct($gaze);
        $this->pending = new \WeakMap();
    }

    protected function register(Dispatcher $events): void
    {
        $events->listen(RequestSending::class, $this->sending(...));
        $events->listen(ResponseReceived::class, $this->received(...));
        $events->listen(ConnectionFailed::class, $this->failed(...));
    }

    public function reset(): void
    {
        $this->pending = new \WeakMap();
        $this->byUrl = [];
    }

    public function sending(RequestSending $event): void
    {
        if (!$this->on('http_client') || GazeManager::sending()) {
            return;
        }
        $request = $event->request;
        $this->gaze->currentTrace()->count('http');
        $method = $request->method();
        $url = $this->gaze->redactor()->url($request->url());
        $details = GazeManager::quietly(fn () => $this->requestDetails($request));
        $origin = AppOrigin::resolve();
        $body = ['method' => $method, 'url' => $url, 'status' => null, 'pending' => true, 'request' => $details];
        $id = $this->send('http', $body, $origin !== null ? ['origin' => $origin] : []);
        $state = ['token' => bin2hex(random_bytes(6)), 'id' => $id, 'hr' => hrtime(true), 'method' => $method, 'url' => $url, 'origin' => $origin, 'request' => $details];
        $this->pending[$request] = $state;
        if (($psr = self::psr($request)) !== null) {
            $this->pending[$psr] = $state;
        }
        $this->byUrl[$method . ' ' . $url][] = \WeakReference::create($request);
    }

    public function received(ResponseReceived $event): void
    {
        $state = $this->take($event->request);
        if ($state === null || !$this->on('http_client')) {
            return;
        }
        $response = $event->response;
        $durationMs = round((hrtime(true) - $state['hr']) / 1e6, 3);
        $body = [
            'method' => $state['method'],
            'url' => $state['url'],
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'request' => $state['request'],
            'response' => GazeManager::quietly(fn () => $this->responseDetails($response)),
        ];
        $mods = ['origin' => $state['origin']];
        if ($response->serverError()) {
            $mods['color'] = 'red';
        } elseif ($response->clientError()) {
            $mods['color'] = 'orange';
        }
        $this->update($state['id'], $body, array_filter($mods, static fn ($v) => $v !== null));
    }

    public function failed(ConnectionFailed $event): void
    {
        $state = $this->take($event->request);
        if ($state === null || !$this->on('http_client')) {
            return;
        }
        $exception = property_exists($event, 'exception') ? $event->exception : null;
        $body = [
            'method' => $state['method'],
            'url' => $state['url'],
            'status' => 0,
            'duration_ms' => round((hrtime(true) - $state['hr']) / 1e6, 3),
            'request' => $state['request'],
            'error' => $exception instanceof \Throwable ? ['class' => $exception::class, 'message' => $exception->getMessage()] : ['message' => 'connection failed'],
        ];
        $this->update($state['id'], $body, array_filter(['origin' => $state['origin'], 'color' => 'red'], static fn ($v) => $v !== null));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $mods
     */
    private function update(?string $id, array $body, array $mods): void
    {
        if ($id === null) {
            $this->send('http', $body, $mods);

            return;
        }
        $this->gaze->send('http', $body, $this->modifiers + $mods, $id);
    }

    /**
     * The pending call of $request: same Request object (ResponseReceived), same PSR request, or
     * the oldest call in flight to the same method + URL (ConnectionFailed wraps a new Request).
     *
     * @return array<string, mixed>|null
     */
    private function take(Request $request): ?array
    {
        $state = $this->pending[$request] ?? null;
        if ($state === null && ($psr = self::psr($request)) !== null) {
            $state = $this->pending[$psr] ?? null;
        }
        if ($state === null) {
            $key = $request->method() . ' ' . $this->gaze->redactor()->url($request->url());
            foreach ($this->byUrl[$key] ?? [] as $ref) {
                $candidate = $ref->get();
                if ($candidate !== null && isset($this->pending[$candidate])) {
                    $state = $this->pending[$candidate];
                    break;
                }
            }
        }
        if ($state === null) {
            return null;
        }
        $forget = [];
        foreach ($this->pending as $object => $s) {
            if ($s['token'] === $state['token']) {
                $forget[] = $object;
            }
        }
        foreach ($forget as $object) {
            unset($this->pending[$object]);
        }
        $key = $state['method'] . ' ' . $state['url'];
        $this->byUrl[$key] = array_values(array_filter(
            $this->byUrl[$key] ?? [],
            fn (\WeakReference $r) => ($o = $r->get()) !== null && isset($this->pending[$o]),
        ));
        if ($this->byUrl[$key] === []) {
            unset($this->byUrl[$key]);
        }

        return $state;
    }

    private static function psr(Request $request): ?object
    {
        try {
            return $request->toPsrRequest();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function requestDetails(Request $request): array
    {
        $redactor = $this->gaze->redactor();
        $details = ['headers' => Values::from($redactor->headers($request->headers()))];
        $type = $request->isJson() ? 'json' : ($request->isMultipart() ? 'multipart' : ($request->isForm() ? 'form' : 'raw'));
        $details['type'] = $type;
        if ($type === 'json' || $type === 'form') {
            $data = $request->data();
            $details['body'] = Values::from(is_array($data) ? $redactor->keys('inputs', $data) : $data);
        } elseif ($type === 'multipart') {
            $parts = [];
            foreach ($request->data() as $part) {
                if (is_array($part)) {
                    $parts[] = ['name' => $part['name'] ?? null, 'filename' => $part['filename'] ?? null];
                }
            }
            $details['body'] = Values::from($parts);
        } else {
            $raw = $request->body();
            if ($raw !== '') {
                [$text, $cut] = Values::cut($raw, self::MAX_BODY);
                $details['body'] = Values::from($text);
                if ($cut > 0) {
                    $details['body_cut'] = $cut;
                }
            }
        }

        return $details;
    }

    /** @return array<string, mixed> */
    private function responseDetails(Response $response): array
    {
        $redactor = $this->gaze->redactor();
        $raw = $response->body();
        $details = [
            'headers' => Values::from($redactor->headers($response->headers())),
            'size' => strlen($raw),
        ];
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $details['body'] = Values::from($redactor->keys('inputs', $json));
        } elseif ($raw !== '') {
            [$text, $cut] = Values::cut($raw, self::MAX_BODY);
            $details['body'] = Values::from($text);
            if ($cut > 0) {
                $details['body_cut'] = $cut;
            }
        }
        $stats = $response->handlerStats();
        if (isset($stats['total_time'])) {
            $details['transfer_ms'] = round((float) $stats['total_time'] * 1000, 3);
        }

        return $details;
    }
}
