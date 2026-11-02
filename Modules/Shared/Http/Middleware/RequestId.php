<?php

namespace Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $incoming = (string) $request->headers->get('X-Request-Id');
        $id = preg_match('/^[A-Za-z0-9-]{1,64}$/D', $incoming) === 1 ? $incoming : (string) Str::uuid();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        // Runs here because this is the outermost middleware, so the status is final.
        if ($request->is('api/*')) {
            $this->logRequest($request, $response, $startedAt);
        }

        return $response;
    }

    /**
     * A logging failure must never turn a successful response into a 500.
     */
    private function logRequest(Request $request, Response $response, int $startedAt): void
    {
        try {
            Log::info('api request', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round((hrtime(true) - $startedAt) / 1e6, 2),
            ]);
        } catch (Throwable $e) {
            error_log('Request log line failed: '.$e->getMessage());
        }
    }
}
