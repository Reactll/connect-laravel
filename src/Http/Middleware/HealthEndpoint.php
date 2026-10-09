<?php

namespace Reactll\Connect\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Reactll\Connect\Connect;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/reactll/health — Reactll asks hourly what this app runs, so no scheduler is needed on the
 * server. Answered before routing (works with cached routes); only a request signed with this site's
 * secret gets an answer: X-Reactll-Signature = hex(HMAC-SHA256(secret, "{timestamp}.health")).
 */
class HealthEndpoint
{
    public function __construct(private Connect $connect) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || trim($request->path(), '/') !== 'api/reactll/health') {
            return $next($request);
        }
        $c = $this->connect->credentials();
        if (! $c) {
            return response()->json(['error' => 'not connected'], 404);
        }
        $ts = (string) $request->header('X-Reactll-Timestamp');
        $expected = hash_hmac('sha256', $ts.'.health', $c['s']);
        if (! ctype_digit($ts) || abs(time() - (int) $ts) > 300 || ! hash_equals($expected, (string) $request->header('X-Reactll-Signature'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return response()->json($this->connect->healthPayload(), 200, ['Cache-Control' => 'no-store']);
    }
}
