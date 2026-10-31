<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Post;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Schema;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\User;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\WelcomeMail;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

final class MacrosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create();
        Schema::seed(2, 1);
        $this->manager()->disable('queries');
        $this->resetCapture();
    }

    public function testModelsAreSentWithAttributesAndRelationsHiddenFieldsExcluded(): void
    {
        $user = User::with('posts')->first();
        gaze()->model($user)->green();
        gaze()->models(User::all());

        $models = $this->added('model');
        $this->assertCount(3, $models);
        $this->assertSame(User::class, $models[0]['body']['class']);
        $this->assertSame('green', $models[0]['color'], 'modifiers apply to the first model');
        $attributes = array_column($models[0]['body']['attributes']['entries'], 1, 0);
        $this->assertSame('User 1', $attributes['name']['v']);
        $this->assertArrayNotHasKey('password', $attributes, '$hidden is honoured');
        $this->assertSame('posts', $models[0]['body']['relations']['entries'][0][0]);
        $this->assertNull($models[1]['body']['relations']);
        $this->assertBatchesValid();
    }

    public function testMailableRendersWithItsEnvelope(): void
    {
        gaze()->mailable(new WelcomeMail());
        $mail = $this->added('mail')[0]['body'];
        $this->assertSame('Welcome aboard', $mail['subject']);
        $this->assertSame('ada@example.com', $mail['to'][0]['email']);
        $this->assertSame('shop@example.com', $mail['from'][0]['email']);
        $this->assertStringContainsString('Welcome, Ada', $mail['html']);
        $this->assertSame(WelcomeMail::class, $mail['mailable']);
    }

    public function testContextAndHiddenContextLeaveGazesOwnKeyOut(): void
    {
        Context::add('tenant', 'acme');
        Context::addHidden('internal', 'x');
        Context::addHidden('gaze.trace', str_repeat('a', 32));
        gaze()->context();
        gaze()->hiddenContext();
        $logs = $this->added('log');
        $this->assertSame('Context', $logs[0]['label']);
        $this->assertSame([['tenant', ['k' => 'str', 'v' => 'acme']]], $logs[0]['body']['values'][0]['entries']);
        $this->assertSame('Hidden Context', $logs[1]['label']);
        $this->assertSame([['internal', ['k' => 'str', 'v' => 'x']]], $logs[1]['body']['values'][0]['entries']);
    }

    public function testCollectionStringableAndTestResponseMacros(): void
    {
        $collection = collect(['a' => 1])->gaze('my items')->map(fn ($v) => $v + 1);
        $this->assertSame(['a' => 2], $collection->all());
        $string = Str::of('hello')->gaze()->upper();
        $this->assertSame('HELLO', (string) $string);
        Route::get('/json', fn () => response()->json(['id' => 1, 'password' => 'x'], 201));
        $this->get('/json')->gaze()->assertCreated();

        $logs = $this->added('log');
        $this->assertSame('my items', $logs[0]['label']);
        $this->assertSame(['k' => 'str', 'v' => 'hello'], $logs[1]['body']['values'][0]);
        $this->assertSame(__FILE__, $logs[0]['origin']['file']);
        $response = $this->added('response')[0];
        $this->assertSame(201, $response['body']['status']);
        $this->assertSame([['id', ['k' => 'int', 'v' => 1]], ['password', ['k' => 'str', 'v' => '********']]], $response['body']['json']['entries']);
        $this->assertBatchesValid();
    }

    public function testBladeDirectivesCompileToTheHelper(): void
    {
        $this->assertSame('<?php gaze($user, 1); ?>', Blade::compileString('@gaze($user, 1)'));
        $this->assertSame('<?php gaze()->measure(); ?>', Blade::compileString('@gazeMeasure'));
        $this->assertStringContainsString("gaze()->label('View data')", Blade::compileString('@gazeAll'));

        $dir = self::root() . DIRECTORY_SEPARATOR . 'views-' . bin2hex(random_bytes(3));
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/card.blade.php', "<div>\n@gaze(\$title)\n@gazeAll\n</div>");
        \Illuminate\Support\Facades\View::addLocation($dir);
        view('card', ['title' => 'Hi'])->render();
        $logs = $this->added('log');
        $this->assertSame(['k' => 'str', 'v' => 'Hi'], $logs[0]['body']['values'][0]);
        $this->assertStringEndsWith('card.blade.php', $logs[0]['origin']['file'], 'compiled views map back to the template');
        $this->assertSame('View data', $logs[1]['label']);
    }

    public function testExceptionMethodAddsTheSqlOfAQueryException(): void
    {
        try {
            DB::select('select * from missing_table where id = ?', [5]);
        } catch (QueryException $e) {
            gaze()->exception($e);
        }
        $exception = $this->added('exception')[0];
        $this->assertSame(QueryException::class, $exception['body']['class']);
        $this->assertStringContainsString('missing_table', json_encode($exception['body']['context']));
        $this->assertSame('red', $exception['color']);
    }

    public function testViewMethodAndEnvWithoutFile(): void
    {
        $dir = self::root() . DIRECTORY_SEPARATOR . 'views-' . bin2hex(random_bytes(3));
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/plain.blade.php', 'x');
        \Illuminate\Support\Facades\View::addLocation($dir);
        gaze()->view(view('plain', ['post' => Post::first()]));
        $view = $this->added('view')[0]['body'];
        $this->assertSame('plain', $view['name']);
        $this->assertSame('post', $view['data']['entries'][0][0]);
    }
}
