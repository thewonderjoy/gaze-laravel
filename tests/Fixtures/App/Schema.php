<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as S;

final class Schema
{
    public static function create(): void
    {
        S::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password')->nullable();
            $t->timestamps();
        });
        S::create('posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->string('title');
        });
        S::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        S::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    /** @return list<User> */
    public static function seed(int $users = 3, int $postsPerUser = 2): array
    {
        $out = [];
        for ($i = 1; $i <= $users; ++$i) {
            $user = User::create(['name' => "User {$i}", 'email' => "u{$i}@example.com", 'password' => 'secret-hash-' . $i]);
            for ($p = 1; $p <= $postsPerUser; ++$p) {
                Post::create(['user_id' => $user->id, 'title' => "Post {$i}.{$p}"]);
            }
            $out[] = $user;
        }

        return $out;
    }
}
