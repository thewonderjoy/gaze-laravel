<?php

// Stand-ins for laravel/octane's events (not installed in this package's dev dependencies).

namespace Laravel\Octane\Events;

if (!class_exists(RequestReceived::class)) {
    class RequestReceived
    {
        public function __construct(public mixed $app = null, public mixed $sandbox = null, public mixed $request = null)
        {
        }
    }

    class RequestTerminated
    {
        public function __construct(public mixed $app = null, public mixed $sandbox = null, public mixed $request = null, public mixed $response = null)
        {
        }
    }
}
