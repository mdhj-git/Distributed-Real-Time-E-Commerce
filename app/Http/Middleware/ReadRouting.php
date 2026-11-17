<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Decides, per request, whether database reads may use the read replica (Requirement 3, Step 6).
 *
 * It is the first middleware of the "api" group (see AppServiceProvider). The replica is used only when
 * DB_READ_HOST points at another server (config/database.php); with a single host this class changes no query.
 *
 * Every read of the request goes to the primary (DB::connection()->useWriteConnectionWhenReading()) when:
 *  - the method is not GET, HEAD or OPTIONS (price snapshot, route-model binding, policies and validation of a write);
 *  - the route has the "reads.primary" alias and CATALOG_READS_ON_PRIMARY is true (catalog: a product that was just
 *    created or deleted must not be a 404 or a ghost during the replica lag);
 *  - the user has a read-your-writes marker: a short-lived Redis key written after a request of that user
 *    modified data (READ_YOUR_WRITES_*). The marker is per user, so it protects the writer only.
 *
 * Laravel's "sticky" option cannot do this alone: it lives on one Connection object, so one request.
 *
 * Failures of the marker (Redis) are logged and never break a response: a failed read means "primary",
 * a failed write means "no marker".
 *
 * Note for Requirement 4 (queue workers, long-lived processes): the flag lives as long as the Connection object.
 * Under PHP-FPM that is one request.
 */
class ReadRouting
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Alias of the pass-through middleware that marks a route as a catalog read (see ReadsOnPrimary). */
    public const CATALOG_ALIAS = 'reads.primary';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $markers = (bool) config('replica.read_your_writes.enabled');

        $primary = ! in_array($request->getMethod(), self::READ_METHODS, true) || $this->isCatalogRead($request);

        if (! $primary && $markers) {
            $primary = $this->hasMarker($request);
        }

        if ($primary) {
            DB::connection()->useWriteConnectionWhenReading();
        }

        // Runs for 4xx and 5xx too: the router turns an exception into a response inside the pipeline.
        $response = $next($request);

        if ($markers) {
            $this->markIfModified();
        }

        return $response;
    }

    private function isCatalogRead(Request $request): bool
    {
        return (bool) config('replica.catalog_reads_on_primary')
            && in_array(self::CATALOG_ALIAS, $request->route()?->gatherMiddleware() ?? [], true);
    }

    /**
     * True when the caller has a marker. The user id is the JWT subject, read without a database query
     * (the guard's own lookup comes later). An invalid or expired token means "no marker": auth:api answers 401.
     */
    private function hasMarker(Request $request): bool
    {
        try {
            $jwt = app('tymon.jwt');
            $token = $jwt->setRequest($request)->getToken();

            if (! $token) {
                return false;
            }

            $subject = $jwt->manager()->decode($token, false)->get('sub');
        } catch (Throwable) {
            return false;
        }

        if (! is_scalar($subject) || $subject === '') {
            return false;
        }

        try {
            return Cache::has(self::key($subject));
        } catch (Throwable $e) {
            Log::warning('replica: marker read failed, reading from the primary', ['error' => $e->getMessage()]);

            return true;
        }
    }

    /** Sets the marker when this request wrote to the database. Never changes the response. */
    private function markIfModified(): void
    {
        try {
            // Look at connections that already exist: a route that never used the database creates none.
            $connection = app('db')->getConnections()[config('database.default')] ?? null;

            if ($connection === null || ! $connection->hasModifiedRecords()) {
                return;
            }

            // The user the guard already holds (register, protected routes) or the token subject. No database query.
            $id = auth('api')->id();

            if ($id === null) {
                return;
            }

            Cache::put(self::key($id), 1, (int) config('replica.read_your_writes.ttl_seconds'));
        } catch (Throwable $e) {
            Log::warning('replica: marker write failed', ['error' => $e->getMessage()]);
        }
    }

    private static function key(int|string $userId): string
    {
        return 'rw:user:'.$userId;
    }
}
