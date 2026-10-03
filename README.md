# Cloudflare Cache for Laravel

Easy Cloudflare edge caching for Laravel apps:

- **Middleware** that sets safe `Cache-Control` headers for public pages
- **Purge** one URL, many URLs, or an entire zone (sync or queued)
- **Optional warming** after purge
- **Model trait** to purge when Filament / Eloquent content changes

Designed for brochure/marketing sites behind Cloudflare — including Laravel Cloud’s Cloudflare edge when you manage the zone yourself for per-URL purge.

## Installation

```bash
composer require nckrtl/cloudflare-cache
```

Publish config (optional):

```bash
php artisan vendor:publish --tag=cloudflare-cache-config
```

## Environment

```env
CLOUDFLARE_CACHE_ENABLED=true
CLOUDFLARE_CACHE_ENVIRONMENTS=production,staging

CLOUDFLARE_ZONE_ID=your-zone-id
CLOUDFLARE_API_TOKEN=your-scoped-token

# Optional
CLOUDFLARE_API_BASE_URL=https://api.cloudflare.com/client/v4

CLOUDFLARE_CACHE_S_MAXAGE=3600
CLOUDFLARE_CACHE_MAX_AGE=0
# 0 disables stale serving — keep at 0 unless you've weighed how long a poisoned
# or broken response could stay servable at the edge before purge/re-fetch.
CLOUDFLARE_CACHE_STALE_WHILE_REVALIDATE=0
CLOUDFLARE_CACHE_STALE_IF_ERROR=0

CLOUDFLARE_CACHE_QUEUE=
CLOUDFLARE_CACHE_PURGE_ASYNC=true
# Soft-fail (log instead of throw) when credentials are missing or the API call fails.
CLOUDFLARE_CACHE_SOFT_FAIL=true

CLOUDFLARE_CACHE_WARM_ENABLED=true
CLOUDFLARE_CACHE_WARM_AFTER_PURGE=false
CLOUDFLARE_CACHE_WARM_TIMEOUT=15
CLOUDFLARE_CACHE_WARM_USER_AGENT="Nckrtl-CloudflareCache/1.0 (+https://github.com/nckrtl/cloudflare-cache)"
```

Create a Cloudflare API token with **Zone → Cache Purge** limited to the site zone.

## Cacheable routes (critical)

Cloudflare will **not** cache responses that set cookies. Do **not** put this middleware only on the default `web` stack.

Declare cacheable pages in their own route file, for example `routes/static.php`, and load it in a cookie-free `static` middleware group outside the `web` group:

```php
// bootstrap/app.php
use Illuminate\Support\Facades\Route;
use NckRtl\CloudflareCache\Support\StaticMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('static')->group(base_path('routes/static.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->group('static', StaticMiddleware::defaults([
            \App\Http\Middleware\HandleInertiaRequests::class,
            // CSP / other cookie-free middleware…
        ]));
    })
    ->create();
```

```php
// routes/static.php
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ProjectController::class, 'index'])->name('projects.index');
Route::get('/pricing', [ProjectController::class, 'pricing'])->name('pricing');
```

Routes that need a session, such as an account page behind `auth`, stay in `routes/web.php`.

`StaticMiddleware::defaults()` is:

1. `SubstituteBindings`
2. Your `$before` stack (Inertia share, etc.)
3. `CacheResponse` (this package)
4. Your `$after` stack

To cache a single route without the `static` group, use the middleware alias:

```php
Route::get('/pricing', ...)->middleware('cloudflare.cache:86400');
```

Default headers:

```http
Cache-Control: public, max-age=0, s-maxage=3600, stale-while-revalidate=0, stale-if-error=0
```

Middleware skips: non-GET, non-2xx, authenticated users, requests with an active session (`web` group), `Set-Cookie` responses, disabled env / package.

### Cloudflare dashboard

For HTML to be eligible, ensure a **Cache Everything** (or equivalent) rule with **Browser TTL: Respect Origin** and **Edge TTL: Use cache-control header from origin (Respect Origin)**. Without the Edge TTL setting, Cloudflare may ignore `s-maxage` and fall back to its own default. Static extensions are cached by Cloudflare by default.

## Inertia + SSR (Cloudflare only)

Document visits and Inertia navigations share the same URL:

| Request | Expects | Edge cache |
|---------|---------|------------|
| Full page load | SSR HTML | Yes — `public, s-maxage=…` |
| Inertia XHR (`X-Inertia: true`) | JSON | **No** — `private, no-store` |

Cloudflare free/pro **does not vary the cache key on `X-Inertia`**. Relying on `Vary` alone will serve HTML to Inertia navigations — store-side headers alone aren't enough either, so you also need **lookup-side** Cache Rules on a zone that actually proxies traffic.

This package:

1. Edge-caches **document** visits only
2. Forces **`private, no-store`** when `X-Inertia` is present
3. Still sets `Vary: Accept-Encoding, X-Inertia` for correctness elsewhere

### Required Cloudflare Cache Rules

Create two Cache Rules, in this order:

1. **Bypass Cache** when it's an Inertia navigation:

   ```text
   any(http.request.headers["x-inertia"][*] == "true")
   ```

2. **Cache Everything** for other eligible `GET`s (the document HTML), with:
   - **Browser TTL**: Respect Origin
   - **Edge TTL**: Use cache-control header from origin (Respect Origin)

   The Edge TTL setting matters — without it Cloudflare may ignore `s-maxage` from this package's headers and fall back to its own default Edge TTL instead.

`not len(http.request.headers["x-inertia"]) > 0` is **not** a valid substitute for rule 1 — `len()` does not operate on an array-valued header field and won't validate.

DNS for the site must be **proxied** (orange cloud) on that zone. Grey-cloud DNS (DNS only) means your Cache Rules never run — traffic hits Laravel Cloud’s managed edge instead, which cannot set per-header bypass rules.

Warming fetches document HTML only (no `X-Inertia` header).

There is **no** second origin cache in this package — only Cloudflare edge headers, purge, and warm.

## Purge

```php
use NckRtl\CloudflareCache\Facades\CloudflareCache;

CloudflareCache::purge('https://example.com/');
CloudflareCache::purge([
    'https://example.com/',
    'https://example.com/projecten/sion',
]);

// Sync (CLI / tests)
CloudflareCache::purge($urls, async: false);

// Purge then warm
CloudflareCache::purge($urls, warm: true);

// Whole zone
CloudflareCache::purgeEverything();
```

### Eloquent / Filament

```php
use NckRtl\CloudflareCache\Concerns\PurgesCloudflareCache;
use NckRtl\CloudflareCache\Contracts\PurgesCloudflareUrls;
use Illuminate\Database\Eloquent\Model;

class PortfolioItem extends Model implements PurgesCloudflareUrls
{
    use PurgesCloudflareCache;

    public function cloudflareCacheUrls(): array
    {
        return array_values(array_filter([
            route('projects', absolute: true),
            route('portfolio-item', $this->slug, absolute: true),
            $this->wasChanged('slug')
                ? route('portfolio-item', $this->getOriginal('slug'), absolute: true)
                : null,
        ]));
    }
}
```

Or call from Filament:

```php
protected function afterSave(): void
{
    CloudflareCache::purge($this->record, warm: true);
}
```

## Artisan

```bash
php artisan cloudflare-cache:purge https://example.com/ https://example.com/studio
php artisan cloudflare-cache:purge --all --sync
php artisan cloudflare-cache:purge https://example.com/ --sync --warm

php artisan cloudflare-cache:warm https://example.com/ --sync
```

## Warming

Off after purge by default (`CLOUDFLARE_CACHE_WARM_AFTER_PURGE=false`). Enable per call with `warm: true`, or set the env flag globally. Warming issues cookie-less GET requests so the edge can re-fill quickly after invalidation.

## Laravel Cloud note

Laravel Cloud’s built-in edge purge API clears the **whole environment**. This package targets **your Cloudflare zone** for precise URL purge (Filament saves). Use both: deploy purge from Cloud, content purge from this package.

If the custom domain is DNS-only to Laravel Cloud, HTML may still be edge-cached by Cloud’s Cloudflare when you send `public, s-maxage`. Without Cache Rules you control, Inertia navigations will get that HTML — either orange-cloud through your zone with the rules above, or stop sharing HTML at the edge (`private`) for those pages.

## Testing

```bash
composer test:unit
composer test
```

## License

MIT
