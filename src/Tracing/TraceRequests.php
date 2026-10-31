<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tracing;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheWonderJoy\Gaze\Laravel\GazeManager;

/**
 * Global middleware (prepended, so it wraps every other one): opens the request's trace before
 * the router runs. The RequestHandled listener sends the summary row; terminate() closes the
 * trace after terminable middleware and terminating callbacks, so their entries still belong
 * to the request.
 */
final class TraceRequests
{
    public function __construct(private readonly GazeManager $gaze)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $traces = $this->gaze->traces();
        if (!$request->attributes->has('_gaze_trace') && !$traces->ignoresRequest($request)) {
            $traces->startRequest($request);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $trace = $request->attributes->get('_gaze_trace');
        if ($trace instanceof Trace) {
            $this->gaze->traces()->pop($trace);
        }
    }
}
