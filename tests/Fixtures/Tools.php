<?php

// Stand-ins for Telescope's and Pulse's storage: they write what they recorded when a request
// ends, from classes in their own namespaces. Required by ToolNoiseTest.

declare(strict_types=1);

namespace Laravel\Telescope\Storage {
    use Illuminate\Support\Facades\DB;

    final class GazeTestEntriesRepository
    {
        public static function store(): void
        {
            DB::insert('insert into telescope_entries (uuid, type, content) values (?, ?, ?)', ['e1', 'request', '{}']);
            DB::insert('insert into telescope_entries_tags (entry_uuid, tag) values (?, ?)', ['e1', 'App\Models\User:1']);
            DB::insert('insert into telescope_entries_tags (entry_uuid, tag) values (?, ?)', ['e1', 'App\Models\User:2']);
        }
    }
}

namespace Laravel\Pulse\Storage {
    use Illuminate\Support\Facades\DB;

    final class GazeTestDatabaseStorage
    {
        public static function store(): void
        {
            DB::insert('insert into pulse_entries (type, key, value) values (?, ?, ?)', ['slow_request', 'GET /', 1]);
            DB::insert('insert into pulse_aggregates (type, key, value) values (?, ?, ?)', ['count', 'GET /', 1]);
        }
    }
}
