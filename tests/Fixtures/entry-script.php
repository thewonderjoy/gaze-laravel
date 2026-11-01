<?php

// Stands in for public/index.php: the only file of the app on the stack while Laravel reads a
// session the way the StartSession middleware does. Required by QueryWatcherTest.

declare(strict_types=1);

use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;

return static function (): void {
    (new DatabaseSessionHandler(DB::connection(), 'sessions', 120))->read('no-such-session');
};
