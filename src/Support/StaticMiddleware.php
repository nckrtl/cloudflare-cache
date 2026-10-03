<?php

declare(strict_types=1);

namespace NckRtl\CloudflareCache\Support;

use Illuminate\Routing\Middleware\SubstituteBindings;
use NckRtl\CloudflareCache\Http\Middleware\CacheResponse;

/**
 * Helpers for building a cookie-free middleware stack for edge-cacheable pages.
 *
 * Intended to be registered as Laravel's `static` middleware group and applied to
 * the route file that holds cacheable pages.
 */
final class StaticMiddleware
{
    /**
     * Default stack for public, cacheable HTML pages.
     *
     * Does not include session or cookie encryption — those set `Set-Cookie`
     * and prevent Cloudflare from caching HTML.
     *
     * @param  list<class-string|string>  $before  Middleware before cache headers (e.g. Inertia share)
     * @param  list<class-string|string>  $after  Middleware after cache headers
     * @return list<class-string|string>
     */
    public static function defaults(array $before = [], array $after = []): array
    {
        return array_values(array_unique([
            SubstituteBindings::class,
            ...$before,
            CacheResponse::class,
            ...$after,
        ]));
    }
}
