<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pass-through. It does nothing itself: it only puts the alias "reads.primary" on a route.
 * ReadRouting (first in the "api" group) looks for that alias and, while CATALOG_READS_ON_PRIMARY is true,
 * sends every read of that request to the primary database (see config/replica.php).
 */
class ReadsOnPrimary
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
