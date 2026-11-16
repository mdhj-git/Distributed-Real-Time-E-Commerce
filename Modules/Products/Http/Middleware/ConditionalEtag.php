<?php

namespace Modules\Products\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional GET for product detail (requirement 3, step 5).
 *
 * Runs after the controller, so the hash covers the final body, including the
 * live stock. A stock change therefore gives a new ETag. Only 200 responses to
 * GET get one: 404 and 422 never do, HEAD is left as it was. Symfony's isNotModified()
 * does the comparison (weak match, comma list, "*") and turns the response
 * into an empty 304 that keeps ETag, Cache-Control and the headers added by
 * the global middleware.
 */
class ConditionalEtag
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // GET only: Laravel empties the body of a HEAD response before this runs, so a HEAD
        // ETag would be the hash of an empty string and would never match the GET ETag.
        if (! config('products.etag_enabled')
            || ! $request->isMethod('GET')
            || $response->getStatusCode() !== 200) {
            return $response;
        }

        $response->setEtag(hash('xxh128', (string) $response->getContent()));

        // The client may store the response but must revalidate it every time (stock is live),
        // and shared caches must not serve it.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');

        $response->isNotModified($request);

        return $response;
    }
}
