<?php

namespace Modules\Products\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Key names, versions and invalidation of the catalog cache (requirement 3, step 4).
 * The only code that knows how the keys look. It uses the Cache facade and nothing else.
 *
 * Three counters, created by Cache::increment (no TTL):
 * - ns: bumped only by products:cache-flush. Invalidates every entry.
 * - list-ver: bumped on every catalog write. Part of every list key.
 * - product-ver:{id}: bumped when that product is written. Part of its detail key.
 *
 * A reader builds the entry key from the versions it read BEFORE it reads the database, and stores
 * under that same key. A write that commits in between bumps the version, so the late store lands
 * on a key nobody reads any more. That is the whole race protection.
 */
class CatalogCache
{
    /** Bump when the shape of a cached array changes, so entries of an older shape are never read. */
    public const KEY_VERSION = 'v1';

    private const DEFAULT_PER_PAGE = 15;

    /**
     * Key of the list entry for these filters. The versions are read here, so call it before the
     * database is read and use the returned key for both the get and the put.
     *
     * @param  array<string, mixed>  $filters  Validated query parameters of GET /products.
     */
    public function listKey(array $filters): string
    {
        [$ns, $listVersion] = $this->versions($this->nsKey(), $this->listVersionKey());

        $category = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
        $search = isset($filters['search']) && $filters['search'] !== '' ? (string) $filters['search'] : null;
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);

        // The hash input is a list in a fixed order, so the same request always gives the same key.
        $hash = sha1(json_encode([$category, $search, $this->pageOf($filters), $perPage], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $this->prefix()."l:{$ns}:{$listVersion}:{$hash}";
    }

    /**
     * The page the paginator will use: same rule as Paginator::resolveCurrentPage(), default 1.
     *
     * @param  array<string, mixed>  $filters
     */
    public function pageOf(array $filters): int
    {
        $page = $filters['page'] ?? null;

        return filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1 ? (int) $page : 1;
    }

    /** Key of the detail entry of one product. Read the versions before the database, as for listKey(). */
    public function detailKey(int $id): string
    {
        [$ns, $productVersion] = $this->versions($this->nsKey(), $this->productVersionKey($id));

        return $this->prefix()."d:{$ns}:{$id}:{$productVersion}";
    }

    public function categoriesKey(): string
    {
        [$ns] = $this->versions($this->nsKey());

        return $this->prefix()."c:{$ns}";
    }

    /**
     * Called after a catalog write has committed. Every list shows product data, so the list version
     * always moves. The product version moves only when the write targets an existing product.
     */
    public function invalidateProduct(?int $productId): void
    {
        Cache::increment($this->listVersionKey());

        if ($productId !== null) {
            Cache::increment($this->productVersionKey($productId));
        }
    }

    /**
     * Invalidates every list, detail and categories entry with one increment. Old entries are never
     * read again and expire by their TTL. Touches no other key, so the rate limiter counters and the
     * JWT blacklist in the same store are safe.
     *
     * @return int The new namespace number.
     */
    public function flush(): int
    {
        return (int) Cache::increment($this->nsKey());
    }

    public function listTtl(): int
    {
        return (int) config('products.cache.list_ttl');
    }

    public function detailTtl(): int
    {
        return (int) config('products.cache.detail_ttl');
    }

    public function categoriesTtl(): int
    {
        return (int) config('products.cache.categories_ttl');
    }

    /**
     * One round trip for the version counters. A missing counter reads as 0.
     *
     * @return array<int, int>
     */
    private function versions(string ...$keys): array
    {
        $values = Cache::many($keys);

        return array_map(fn (string $key): int => (int) ($values[$key] ?? 0), $keys);
    }

    private function prefix(): string
    {
        return 'products:'.self::KEY_VERSION.':';
    }

    private function nsKey(): string
    {
        return $this->prefix().'ns';
    }

    private function listVersionKey(): string
    {
        return $this->prefix().'list-ver';
    }

    private function productVersionKey(int $id): string
    {
        return $this->prefix()."product-ver:{$id}";
    }
}
