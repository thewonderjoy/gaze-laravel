<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
