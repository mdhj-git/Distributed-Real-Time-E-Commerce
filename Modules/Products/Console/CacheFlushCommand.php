<?php

namespace Modules\Products\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Products\Services\CatalogCache;

/**
 * Run it after anything that changes the catalog without going through ProductService:
 * migrate:fresh, db:seed, perf:seed, direct SQL, a changed APP_URL (image URLs are cached).
 * It is not php artisan cache:clear: that flushes the whole store, including the rate limiter
 * counters and the JWT blacklist.
 */
#[Signature('products:cache-flush')]
#[Description('Invalidate every cached product list, product detail and category entry')]
class CacheFlushCommand extends Command
{
    public function handle(CatalogCache $cache): int
    {
        $namespace = $cache->flush();

        $this->info("Catalog cache invalidated. Namespace is now {$namespace}.");
        $this->line('Old entries are not read any more and expire by their TTL. No other cache key was touched.');

        return self::SUCCESS;
    }
}
