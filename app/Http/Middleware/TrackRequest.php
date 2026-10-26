<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every API request an ID (added to all log lines) and reports the total
 * request time in the X-Response-Time header.
 */
class TrackRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        $requestId = $request->header('X-Request-Id');

        if (! is_string($requestId) || ! preg_match('/^[A-Za-z0-9-]{8,64}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        Context::add('request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Response-Time', (int) round((hrtime(true) - $startedAt) / 1_000_000).'ms');

        return $response;
    }
}
