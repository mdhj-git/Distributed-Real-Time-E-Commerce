<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * TEMPORARY (Requirement 3). Stays until the final cleanup (Step 7).
 *
 * Active only when PERF_QUERY_LOG=true and only for web requests (never for artisan).
 * It must stay OFF during every k6 run: writing to the 9p-mounted storage would distort the numbers.
 *
 * Output: storage/logs/perf-queries.jsonl, one JSON line per query, one per lazy-loading violation
 * (logged only, never thrown) and one summary line per request. Binding values are logged in `values`
 * (strings cut to 100 chars), except for /auth/* requests where only the count is kept.
 */
class PerfQueryCounterServiceProvider extends ServiceProvider
{
    private const FILE = 'logs/perf-queries.jsonl';

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    public function boot(): void
    {
        if (! filter_var(env('PERF_QUERY_LOG', false), FILTER_VALIDATE_BOOLEAN) || $this->app->runningInConsole()) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            $row = [
                'type' => 'query',
                'sql' => $query->sql,
                'bindings' => count($query->bindings),
                'ms' => round($query->time, 3),
                'connection' => $query->connectionName,
            ];

            // Binding values are needed to rebuild a query for EXPLAIN. Never recorded for /auth/*
            // (login carries credentials). Strings are cut to 100 characters.
            if (! $this->isAuthRequest()) {
                $row['values'] = array_map(self::cut(...), $query->bindings);
            }

            $this->buffer[] = $row;
        });

        // Log only: a custom handler replaces the default exception, so nothing is ever thrown.
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            $this->buffer[] = [
                'type' => 'lazy_loading',
                'model' => get_class($model),
                'relation' => $relation,
            ];
        });

        Event::listen(RequestHandled::class, fn (RequestHandled $event) => $this->flush($event));
    }

    private function isAuthRequest(): bool
    {
        return str_contains('/'.$this->app['request']->path().'/', '/auth/');
    }

    private static function cut(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > 100 ? mb_substr($value, 0, 100).'...' : $value;
        }

        return is_scalar($value) || $value === null ? $value : get_debug_type($value);
    }

    private function flush(RequestHandled $event): void
    {
        $request = $event->request;
        $id = bin2hex(random_bytes(4));
        $route = $request->route()?->uri() ?? 'unrouted';

        $lines = [];
        $count = 0;
        $totalMs = 0.0;

        foreach ($this->buffer as $row) {
            if ($row['type'] === 'query') {
                $count++;
                $totalMs += $row['ms'];
            }

            $lines[] = json_encode(['req' => $id, 'route' => $route] + $row, JSON_UNESCAPED_SLASHES);
        }

        $lines[] = json_encode([
            'type' => 'request',
            'req' => $id,
            'method' => $request->method(),
            'route' => $route,
            'path' => $request->path(),
            'query' => $request->getQueryString(),
            'status' => $event->response->getStatusCode(),
            'queries' => $count,
            'ms' => round($totalMs, 3),
            'request_ms' => defined('LARAVEL_START') ? round((microtime(true) - LARAVEL_START) * 1000, 1) : null,
        ], JSON_UNESCAPED_SLASHES);

        $path = storage_path(self::FILE);
        $isNew = ! file_exists($path);
        file_put_contents($path, implode("\n", $lines)."\n", FILE_APPEND | LOCK_EX);

        if ($isNew) {
            @chmod($path, 0666); // php-fpm creates it; let the host user truncate it too
        }

        $this->buffer = [];
    }
}
