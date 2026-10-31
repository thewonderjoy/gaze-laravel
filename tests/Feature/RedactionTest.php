<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

final class RedactionTest extends TestCase
{
    protected array $gazeConfig = ['watchers.requests' => true];

    public function testRequestHeadersSessionAndInputsAreMaskedByDefault(): void
    {
        Route::middleware('web')->post('/login', function () {
            session(['_token' => 'csrf-value', 'cart' => [1, 2]]);

            return response()->json(['token' => 'resp-token', 'ok' => true]);
        });
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->withHeaders(['Authorization' => 'Bearer top-secret', 'X-XSRF-TOKEN' => 'xsrf-value', 'Accept' => 'application/json'])
            ->withCookie('laravel_session', 'cookie-value')
            ->post('/login?password=qs-pass', ['email' => 'ada@example.com', 'password' => 'hunter2', 'password_confirmation' => 'hunter2', '_token' => 'form-token'])
            ->assertOk();

        $summary = $this->added('request')[0];
        $json = json_encode($summary);
        foreach (['top-secret', 'xsrf-value', 'cookie-value', 'hunter2', 'form-token', 'csrf-value', 'qs-pass'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} leaked");
        }
        $this->assertStringContainsString('ada@example.com', $json);
        $this->assertStringContainsString('"authorization",{"k":"str","v":"********"}', $json);
        $this->assertStringContainsString('password=%2A%2A%2A%2A%2A%2A%2A%2A', $summary['body']['url']);
        $this->assertSame('json', $summary['body']['response']['entries'][0][1]['v']);
        $this->assertStringContainsString('"cart"', $json);
        $this->assertBatchesValid();
    }

    public function testEnvValuesAreMaskedAndSensitiveParametersNeverLeave(): void
    {
        $file = self::root() . DIRECTORY_SEPARATOR . 'env-' . bin2hex(random_bytes(3));
        file_put_contents($file, "APP_NAME=Shop\nAPP_KEY=base64:abc\nDB_PASSWORD=dbpass\nSTRIPE_SECRET=sk_live\nMAIL_FROM=shop@example.com\n");
        gaze()->env(null, $file);
        gaze()->env(['APP_NAME', 'DB_PASSWORD'], $file);

        $tables = $this->added('table');
        $this->assertSame('.env', $tables[0]['label']);
        $rows = array_column($tables[0]['body']['entries'], 1, 0);
        $this->assertSame('Shop', $rows['APP_NAME']['v']);
        $this->assertSame('shop@example.com', $rows['MAIL_FROM']['v']);
        $this->assertSame('********', $rows['APP_KEY']['v']);
        $this->assertSame('********', $rows['DB_PASSWORD']['v']);
        $this->assertSame('********', $rows['STRIPE_SECRET']['v']);
        $this->assertSame(['APP_NAME', 'DB_PASSWORD'], array_column($tables[1]['body']['entries'], 0));

        $login = static function (string $user, #[\SensitiveParameter] string $password): void {
            throw new \RuntimeException('login failed for ' . $user);
        };
        // built at runtime: the literal must not appear in the code snippets of exception frames
        $secret = strrev('evitisnes-dr0wss@p');
        $other = strrev('terces-rehtona');
        try {
            $login('ada', $secret);
        } catch (\RuntimeException $e) {
            Log::error('auth', ['args' => $e->getTrace()[0]['args'] ?? [], 'exception' => $e]);
            Log::warning('raw', ['value' => new \SensitiveParameterValue($other)]);
        }
        $all = json_encode($this->entries());
        $this->assertStringNotContainsString($secret, $all);
        $this->assertStringNotContainsString($other, $all);
        $this->assertStringNotContainsString('dbpass', $all);
        $this->assertStringNotContainsString('sk_live', $all);
        $this->assertBatchesValid();
    }

    public function testATraceparentFromTheBrowserIsContinued(): void
    {
        Route::get('/api/ping', fn () => 'pong');
        $this->withHeaders(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->get('/api/ping')->assertOk();
        $this->withHeaders(['traceparent' => 'garbage'])->get('/api/ping')->assertOk();

        $summaries = $this->added('request');
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $summaries[0]['trace']['id']);
        $this->assertSame('00f067aa0ba902b7', $summaries[0]['trace']['parent']);
        $this->assertNotSame('4bf92f3577b34da6a3ce929d0e0e4736', $summaries[1]['trace']['id']);
        $this->assertArrayNotHasKey('parent', $summaries[1]['trace']);
        $this->assertBatchesValid();
    }

    public function testIgnoredPathsGetNoTrace(): void
    {
        Route::get('/telescope/requests', fn () => 'telescope');
        $this->get('/telescope/requests')->assertOk();
        $this->assertSame([], $this->added('request'));
    }
}
