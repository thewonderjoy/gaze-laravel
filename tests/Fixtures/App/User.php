<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
