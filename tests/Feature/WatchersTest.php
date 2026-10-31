<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Symfony\Component\VarDumper\VarDumper;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\OrderShipped;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\WelcomeMail;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Watchers\DumpHandler;

final class WatchersTest extends TestCase
{
    public function testLogsWithLevelsColoursAndRedactedContext(): void
    {
        Log::info('user signed in', ['user' => 7, 'password' => 'hunter2']);
        Log::warning('disk almost full');
        Log::error('payment failed');

        $logs = $this->added('app_log');
        $this->assertSame(['info', 'warning', 'error'], array_map(static fn ($e) => $e['body']['level'], $logs));
        $this->assertSame('user signed in', $logs[0]['body']['message']);
        $this->assertArrayNotHasKey('color', $logs[0]);
        $this->assertSame('orange', $logs[1]['color']);
        $this->assertSame('red', $logs[2]['color']);
        $this->assertStringNotContainsString('hunter2', json_encode($logs));
        $this->assertSame(['password', ['k' => 'str', 'v' => '********']], $logs[0]['body']['context']['entries'][1]);
        $this->assertNull($logs[1]['body']['context']);
        $this->assertSame(__FILE__, $logs[0]['origin']['file']);
        $this->assertBatchesValid();
    }

    public function testReportedExceptionsAndErrorsWithFramesAndRequestContext(): void
    {
        Route::get('/boom', function () {
            throw new \RuntimeException('Payment gateway timeout');
        });
        Route::get('/type-error', function () {
            return strlen([]); // a PHP \Error, which laravel-ray ignored
        });
        $this->get('/boom')->assertStatus(500);
        $this->get('/type-error')->assertStatus(500);

        $exceptions = $this->added('exception');
        $this->assertCount(2, $exceptions);
        $e = $exceptions[0];
        $this->assertSame(\RuntimeException::class, $e['body']['class']);
        $this->assertSame('Payment gateway timeout', $e['body']['message']);
        $this->assertSame('red', $e['color']);
        $this->assertSame(__FILE__, $e['body']['frames'][0]['file']);
        $this->assertNotEmpty($e['body']['frames'][0]['snippet']);
        $this->assertSame('http', $e['trace']['kind']);
        $context = json_encode($e['body']['context']);
        $this->assertStringContainsString('/boom', $context);
        $this->assertSame(\TypeError::class, $exceptions[1]['body']['class']);
        $this->assertSame([], $this->added('app_log'), 'an exception is not also sent as a log line');

        $summary = $this->added('request')[0];
        $this->assertSame(500, $summary['body']['status']);
        $this->assertSame('red', $summary['color']);
        $this->assertSame(1, $summary['body']['exceptions']);
        $this->assertBatchesValid();
    }

    public function testMailsCarryTheFullEnvelope(): void
    {
        Mail::send(new WelcomeMail());

        $mail = $this->added('mail')[0]['body'];
        $this->assertSame('Welcome aboard', $mail['subject']);
        $this->assertSame([['email' => 'shop@example.com', 'name' => 'Shop']], $mail['from']);
        $this->assertSame([['email' => 'ada@example.com', 'name' => 'Ada Lovelace']], $mail['to']);
        $this->assertSame([['email' => 'cc@example.com', 'name' => null]], $mail['cc']);
        $this->assertSame([['email' => 'audit@example.com', 'name' => null]], $mail['bcc']);
        $this->assertSame([['email' => 'help@example.com', 'name' => null]], $mail['reply_to']);
        $this->assertSame(WelcomeMail::class, $mail['mailable']);
        $this->assertStringContainsString('<h1>Welcome, Ada</h1>', $mail['html']);
        $this->assertSame('invoice.csv', $mail['attachments'][0]['name']);
        $this->assertSame('text/csv', $mail['attachments'][0]['content_type']);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages(), 'the mail was still sent');
        $this->assertBatchesValid();
    }

    public function testHttpClientRequestAndResponseArePairedInOneEntry(): void
    {
        gaze()->showHttpClientRequests();
        Http::fake([
            'api.example.com/orders*' => Http::response(['id' => 5, 'token' => 'abc'], 201, ['X-Trace' => 'yes']),
            'down.example.com/*' => Http::failedConnection('Connection refused'),
        ]);

        Http::withToken('secret-token')->post('https://api.example.com/orders?api_password=pw', ['item' => 'book', 'password' => 'hunter2']);
        try {
            Http::get('https://down.example.com/ping');
        } catch (ConnectionException) {
        }

        $state = array_values(array_filter($this->state(), static fn ($e) => ($e['kind'] ?? '') === 'http'));
        $this->assertCount(2, $state, 'one entry per call');
        $ok = $state[0];
        $this->assertSame('POST', $ok['body']['method']);
        $this->assertSame(201, $ok['body']['status']);
        $this->assertIsNumeric($ok['body']['duration_ms']);
        $this->assertSame('https://api.example.com/orders?api_password=%2A%2A%2A%2A%2A%2A%2A%2A', $ok['body']['url']);
        $json = json_encode($state);
        $this->assertStringNotContainsString('secret-token', $json);
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringContainsString('"authorization",{"k":"str","v":"********"}', $json);

        $ops = array_values(array_filter($this->entries(), static fn ($e) => ($e['kind'] ?? '') === 'http'));
        $this->assertSame(['add', 'update', 'add', 'update'], array_column($ops, 'op'));
        $this->assertSame($ops[0]['id'], $ops[1]['id']);
        $this->assertTrue($ops[0]['body']['pending']);

        $failed = $state[1];
        $this->assertSame(0, $failed['body']['status']);
        $this->assertSame('red', $failed['color']);
        $this->assertStringContainsString('Connection refused', $failed['body']['error']['message']);
        $this->assertBatchesValid();
    }

    public function testDumpsGoToGazeAndStillReachTheOriginalOutput(): void
    {
        $handler = VarDumper::setHandler(null);
        $this->assertInstanceOf(DumpHandler::class, $handler);
        $printed = [];
        $original = $handler->previous;
        $handler->previous = static function ($value) use (&$printed) {
            $printed[] = $value;
        };
        VarDumper::setHandler($handler);

        dump(['order' => 5]); $line = __LINE__;

        $this->assertSame([['order' => 5]], $printed, 'the previous handler still ran');
        $log = $this->added('log')[0];
        $this->assertSame(['k' => 'map', 'count' => 1, 'entries' => [['order', ['k' => 'int', 'v' => 5]]]], $log['body']['values'][0]);
        $this->assertSame(__FILE__, $log['origin']['file']);
        $this->assertSame($line, $log['origin']['line']);

        gaze()->stopShowingDumps();
        dump('not sent');
        $handler->previous = $original;
        $this->assertSame([['order' => 5], 'not sent'], $printed, 'output still works with the watcher off');
        $this->assertCount(1, $this->added('log'));
    }

    public function testEventsWatcherIsRegisteredOnlyWhenSwitchedOn(): void
    {
        $this->assertArrayNotHasKey('*', $this->packageListeners());
        gaze()->showEvents();
        $this->assertArrayHasKey('*', $this->packageListeners());
        Event::dispatch(new OrderShipped(42));
        Event::dispatch('custom.event', ['a' => 1]);
        gaze()->stopShowingEvents();
        Event::dispatch(new OrderShipped(43));

        $events = array_values(array_filter($this->added('event'), static fn ($e) => in_array($e['body']['name'], [OrderShipped::class, 'custom.event'], true)));
        $this->assertCount(2, $events);
        $this->assertTrue($events[0]['body']['class_based']);
        $this->assertSame(OrderShipped::class, $events[0]['body']['event']['cls']);
        $this->assertNull($events[0]['body']['payload']);
        $this->assertSame('custom.event', $events[1]['body']['name']);
        $this->assertNotNull($events[1]['body']['payload']);
        $this->assertBatchesValid();
    }

    public function testViewsAndCache(): void
    {
        $dir = self::root() . DIRECTORY_SEPARATOR . 'views-' . bin2hex(random_bytes(3));
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/greeting.blade.php', '<p>Hello {{ $name }}</p>@include(\'part\')');
        file_put_contents($dir . '/part.blade.php', '<i>part</i>');
        View::addLocation($dir);

        gaze()->showViews();
        gaze()->showCache();
        $html = view('greeting', ['name' => 'Ada'])->render();
        Cache::put('counter', 0, 60);
        Cache::get('counter');
        Cache::get('missing');
        Cache::forget('counter');

        $this->assertStringContainsString('Hello Ada', $html);
        $views = $this->added('view');
        $this->assertSame(['greeting', 'part'], array_map(static fn ($v) => $v['body']['name'], $views));
        $this->assertStringEndsWith('greeting.blade.php', $views[0]['body']['path']);
        $this->assertSame(['name', ['k' => 'str', 'v' => 'Ada']], $views[0]['body']['data']['entries'][0]);

        $cache = $this->added('cache');
        $this->assertSame(['written', 'hit', 'missed', 'forgotten'], array_map(static fn ($c) => $c['body']['event'], $cache));
        $this->assertSame(['k' => 'int', 'v' => 0], $cache[1]['body']['value'], 'a falsy value is still shown');
        $this->assertTrue($cache[1]['body']['hit']);
        $this->assertSame(60, $cache[0]['body']['ttl']);
        $this->assertBatchesValid();
    }

    public function testDeprecationsAreFilteredUnlessSwitchedOn(): void
    {
        $deprecation = new \ErrorException('Method foo() is deprecated', 0, E_USER_DEPRECATED, __FILE__, __LINE__);
        Log::warning('Method foo() is deprecated', ['exception' => $deprecation]);
        Log::info('this plan is deprecated'); // an ordinary line mentioning "deprecated" is NOT dropped
        gaze()->showDeprecations();
        Log::warning('Method bar() is deprecated', ['exception' => new \ErrorException('Method bar() is deprecated', 0, E_DEPRECATED)]);

        $logs = $this->added('app_log');
        $this->assertSame(['this plan is deprecated', 'Method bar() is deprecated'], array_map(static fn ($l) => $l['body']['message'], $logs));
        $this->assertSame('Deprecation', $logs[1]['label']);
        $this->assertSame([], $this->added('exception'));
    }
}
