<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

final class RelayTest extends TestCase
{
    protected string $appEnv = 'local';

    private function batch(): string
    {
        return (string) json_encode([
            'v' => 1,
            'client' => ['name' => '@thewonderjoy/gaze-js', 'version' => '0.1.0', 'runtime' => 'browser'],
            'session' => 'browser-session-1',
            'entries' => [['op' => 'add', 'id' => 'b-000000001', 'seq' => 0, 'kind' => 'log', 'body' => ['values' => [['k' => 'str', 'v' => 'from the page']]]]],
        ]);
    }

    public function testABrowserBatchIsForwardedWithoutAnyIncomingHeader(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('gaze.relay'));
        $body = $this->batch();
        $response = $this->call('POST', '/_gaze', [], ['laravel_session' => 'cookie-value'], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_AUTHORIZATION' => 'Bearer browser-token',
            'HTTP_ORIGIN' => 'http://localhost',
        ], $body);
        $this->assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(['accepted' => 1], $response->json());

        $forwarded = array_values(array_filter($this->requests(), static fn ($r) => $r['method'] === 'POST' && $r['body'] === $body));
        $this->assertCount(1, $forwarded);
        $headers = array_change_key_case($forwarded[0]['headers'], CASE_LOWER);
        $this->assertArrayNotHasKey('authorization', $headers);
        $this->assertArrayNotHasKey('cookie', $headers);
        $this->assertArrayNotHasKey('origin', $headers);
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertSame([], $this->added('request'), 'the relay itself is not traced');
    }

    public function testInvalidRequestsAreRefused(): void
    {
        $json = ['CONTENT_TYPE' => 'application/json'];
        $this->call('POST', '/_gaze', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $this->batch())->assertStatus(415);
        $this->call('POST', '/_gaze', [], [], [], $json, '{"v":2}')->assertStatus(400);
        $this->call('POST', '/_gaze', [], [], [], $json, 'not json')->assertStatus(400);
        $this->call('POST', '/_gaze', [], [], [], $json + ['HTTP_ORIGIN' => 'https://evil.example'], $this->batch())->assertStatus(403);
        $this->app['config']->set('gaze.relay.max_bytes', 1024);
        $this->call('POST', '/_gaze', [], [], [], $json, str_repeat(' ', 2048) . $this->batch())->assertStatus(413);
        $this->assertSame([], array_filter($this->requests(), static fn ($r) => $r['method'] === 'POST'));
    }

    public function testAnUnreachableAppIsA502(): void
    {
        $this->app['config']->set('gaze.port', 23649);
        \TheWonderJoy\Gaze\Gaze::configure(['port' => 23649, 'connect_timeout_ms' => 100]);
        $response = $this->call('POST', '/_gaze', [], [], [], ['CONTENT_TYPE' => 'application/json'], $this->batch());
        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }
}
