<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class FailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new \RuntimeException('job exploded');
    }
}
