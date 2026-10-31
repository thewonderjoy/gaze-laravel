<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TheWonderJoy\Gaze\Laravel\Support\Redactor;
use TheWonderJoy\Gaze\Laravel\Support\SerializedValue;
use TheWonderJoy\Gaze\Laravel\Support\Sql;

final class SupportTest extends TestCase
{
    public function testFingerprintIgnoresBindingsLiteralsListsAndWhitespace(): void
    {
        $a = Sql::fingerprint('select * from "users" where "id" = ? limit 1', 'mysql');
        $this->assertSame($a, Sql::fingerprint("SELECT *  FROM \"users\"\n WHERE \"id\" = 42 LIMIT 1", 'mysql'));
        $this->assertSame($a, Sql::fingerprint("select * from \"users\" where \"id\" = 'x' limit 1 -- note", 'mysql'));
        $this->assertNotSame($a, Sql::fingerprint('select * from "users" where "id" = ? limit 1', 'pgsql'), 'the connection is part of it');
        $this->assertNotSame($a, Sql::fingerprint('select * from "posts" where "id" = ? limit 1', 'mysql'));
        $this->assertSame(
            Sql::fingerprint('select * from t where id in (?, ?, ?)'),
            Sql::fingerprint('select * from t where id in (1, 2, 3, 4, 5, 6)'),
        );
        $this->assertSame(
            Sql::fingerprint('insert into t (a, b) values (?, ?)'),
            Sql::fingerprint('insert into t (a, b) values (?, ?), (?, ?), (?, ?)'),
        );
        $this->assertSame('select * from t where c = ? and d in (?+)', Sql::shape("select * from t where c = 'it''s' and d in ($1, $2)"));
        $this->assertMatchesRegularExpression('/^q:[0-9a-f]{16}$/', $a);
    }

    public function testTheSchemaCopyMatchesTheProtocol(): void
    {
        $protocol = dirname(__DIR__, 4) . '/protocol/schema';
        if (!is_dir($protocol)) {
            $this->markTestSkipped('outside the monorepo');
        }
        $this->assertFileEquals($protocol . '/batch.schema.json', dirname(__DIR__) . '/schema/batch.schema.json', 'tests/schema is stale: copy protocol/schema again');
        $this->assertFileEquals($protocol . '/hello.schema.json', dirname(__DIR__) . '/schema/hello.schema.json');
    }

    public function testStatementType(): void
    {
        $this->assertSame('select', Sql::type('  select 1'));
        $this->assertSame('insert', Sql::type("/* app */ INSERT INTO t VALUES (1)"));
        $this->assertSame('insert', Sql::type('replace into t values (1)'));
        $this->assertSame('update', Sql::type("-- note\nupdate t set a = 1"));
        $this->assertSame('delete', Sql::type('with old as (select id from t) delete from t where id in (select id from old)'));
        $this->assertSame('select', Sql::type('with a as (select 1), b as (select 2) select * from a, b'));
        $this->assertSame('select', Sql::type('(select 1) union (select 2)'));
        $this->assertSame('other', Sql::type('create table x (id int)'));
    }

    public function testPlaceholderColumns(): void
    {
        $this->assertSame(['name', 'email', 'password', 'name', 'email', 'password'], Sql::placeholderColumns('insert into `users` (`name`, `email`, `password`) values (?, ?, ?), (?, ?, ?)', 6));
        $this->assertSame(['password', 'id'], Sql::placeholderColumns('update "users" set "password" = ? where "users"."id" = ?', 2));
        $this->assertSame(['token', 'token', 'name'], Sql::placeholderColumns("select * from t where token in (?, ?) and name like ? and x = '?'", 3));
        $this->assertSame([null], Sql::placeholderColumns('select ?', 1));
    }

    public function testRedactorPatterns(): void
    {
        $r = new Redactor([
            'mask' => '***',
            'headers' => ['authorization', 'cookie'],
            'session' => ['_token'],
            'env' => ['APP_KEY', '*_SECRET'],
            'inputs' => ['*password*'],
        ], ['card_*']);
        $this->assertTrue($r->matches('headers', 'Authorization'));
        $this->assertFalse($r->matches('headers', 'accept'));
        $this->assertTrue($r->matches('env', 'STRIPE_SECRET'));
        $this->assertTrue($r->matches('env', 'app_key'));
        $this->assertTrue($r->matches('inputs', 'password_confirmation'));
        $this->assertTrue($r->matches('inputs', 'current_password'));
        $this->assertTrue($r->matches('inputs', 'card_number'), 'hello.redact patterns apply to every category');
        $this->assertTrue($r->matches('headers', 'card_cvc'));
        $this->assertSame(['authorization' => '***', 'accept' => 'a, b'], $r->headers(['Authorization' => ['Bearer x'], 'Accept' => ['a', 'b']]));
        $this->assertSame(['user' => ['name' => 'a', 'password' => '***'], 'p' => '***'], $r->keys('inputs', ['user' => ['name' => 'a', 'password' => 'x'], 'p' => new \SensitiveParameterValue('s')]));
        $this->assertSame('/x?a=1&password=%2A%2A%2A&card_no=%2A%2A%2A&b', $r->url('/x?a=1&password=secret&card_no=4111&b'));
        $this->assertTrue($r->sqlMayHaveSecrets('update users set password = ?'));
        $this->assertFalse($r->sqlMayHaveSecrets('select * from posts'));
    }

    public function testSerializedJobCommandsAreReadWithoutUnserialize(): void
    {
        // a class that does not exist: unserialize() would produce __PHP_Incomplete_Class; nothing is autoloaded here
        $s = static fn (string $v): string => 's:' . strlen($v) . ':"' . $v . '";';
        $o = static fn (string $cls, string ...$pairs): string => 'O:' . strlen($cls) . ':"' . $cls . '":' . (count($pairs) / 2) . ':{' . implode('', $pairs) . '}';
        $serialized = $o(
            'App\Jobs\NeverLoadedJob',
            $s('user'), $o('Illuminate\Contracts\Database\ModelIdentifier', $s('class'), $s('App\Models\User'), $s('id'), 'i:7;'),
            $s("\0*\0password"), $s('hunter2'),
            $s("\0App\\Jobs\\X\0sec"), 'd:1.5;',
            $s('tags'), 'a:2:{i:0;s:1:"a";i:1;b:1;}',
        );
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($loader);
        try {
            $value = SerializedValue::parse($serialized, static fn (string $name) => $name === 'password');
        } finally {
            spl_autoload_unregister($loader);
        }
        $this->assertSame([], $autoloaded, 'no class was loaded');
        $this->assertSame('obj', $value['k']);
        $this->assertSame('App\Jobs\NeverLoadedJob', $value['cls']);
        $props = array_column($value['props'], null, 'n');
        $this->assertSame('Illuminate\Contracts\Database\ModelIdentifier', $props['user']['val']['cls']);
        $this->assertSame(['k' => 'int', 'v' => 7], $props['user']['val']['props'][1]['val']);
        $this->assertSame('prot', $props['password']['vis']);
        $this->assertSame(['k' => 'str', 'v' => '********'], $props['password']['val']);
        $this->assertSame('priv', $props['sec']['vis']);
        $this->assertSame('App\Jobs\X', $props['sec']['decl']);
        $this->assertSame(['k' => 'list', 'count' => 2, 'items' => [['k' => 'str', 'v' => 'a'], ['k' => 'bool', 'v' => true]]], $props['tags']['val']);

        $this->assertNull(SerializedValue::parse('not serialized'));
        $this->assertNull(SerializedValue::parse('s:10:"short";'));
        $this->assertNull(SerializedValue::parse('a:1:{i:0;i:1;}trailing'));
    }
}
