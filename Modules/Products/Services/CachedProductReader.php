<?php

namespace Modules\Products\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Products\Contracts\Exceptions\InventoryUnavailableException;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Http\Resources\CategoryResource;
use Modules\Products\Http\Resources\ProductResource;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;

/**
 * Cached reads for the three public catalog routes (requirement 3, step 4). Used ONLY by those
 * routes. ProductService stays uncached, because its write methods call find() inside transactions
 * to build their responses.
 *
 * What is stored: plain arrays (the resource output, with "stock" set to null), never models.
 * Stock is never cached: it is read live from the inventory service on every call and written into
 * the "stock" slot, which keeps the key order of the JSON body.
 *
 * Every method returns ['hit' => bool, 'value' => ...] so the controller can set X-Cache.
 */
class CachedProductReader
{
    public function __construct(
        private ProductService $products,
        private CatalogCache $cache,
        private InventoryService $inventory,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Validated query parameters of GET /products.
     * @return array{hit: bool, value: LengthAwarePaginator} A paginator of arrays.
     */
    public function list(array $filters): array
    {
        // The key (with its versions) is built before anything is read from the database.
        $key = $this->cache->listKey($filters);
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['items'])) {
            return [
                'hit' => true,
                'value' => new LengthAwarePaginator(
                    $this->withLiveStock($cached['items']),
                    $cached['total'],
                    $cached['per_page'],
                    $cached['current_page'],
                ),
            ];
        }

        $paginator = $this->fromPrimary(fn () => $this->products->paginate($filters));

        // ProductService::paginate() has already attached the live stock to the models.
        $live = $this->toArrays($paginator->items());

        // Never store under a key whose page differs from the page that was really read.
        if ($paginator->currentPage() === $this->cache->pageOf($filters)) {
            Cache::put($key, [
                'items' => $this->withoutStock($live),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
            ], $this->cache->listTtl());
        }

        return [
            'hit' => false,
            'value' => new LengthAwarePaginator($live, $paginator->total(), $paginator->perPage(), $paginator->currentPage()),
        ];
    }

    /**
     * A missing or soft-deleted product throws ModelNotFoundException from find() (404). Nothing is
     * stored in that case.
     *
     * @return array{hit: bool, value: array<string, mixed>}
     */
    public function detail(int $id): array
    {
        $key = $this->cache->detailKey($id);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return ['hit' => true, 'value' => $this->withLiveStock([$cached])[0]];
        }

        $product = $this->fromPrimary(fn () => $this->products->find($id));
        $live = $this->toArrays([$product])[0];

        Cache::put($key, $this->withoutStock([$live])[0], $this->cache->detailTtl());

        return ['hit' => false, 'value' => $live];
    }

    /**
     * @return array{hit: bool, value: array<int, array<string, mixed>>}
     */
    public function categories(): array
    {
        $key = $this->cache->categoriesKey();
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return ['hit' => true, 'value' => $cached];
        }

        $value = $this->fromPrimary(
            fn () => json_decode(
                json_encode(CategoryResource::collection(Category::query()->orderBy('name')->get()), JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        Cache::put($key, $value, $this->cache->categoriesTtl());

        return ['hit' => false, 'value' => $value];
    }

    /**
     * Runs a fill on the primary database. Connection::usingWritePdo() makes getReadPdo() return the
     * write PDO for every query on the connection while the callback runs (count query, page query and
     * the eager loads of category and images), and restores the previous value in a finally block.
     * Builder::useWritePdo() would not be enough: the eager loads are separate builders.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function fromPrimary(\Closure $callback): mixed
    {
        return DB::connection()->usingWritePdo(fn () => $callback());
    }

    /**
     * Same output as the JSON body of the resource, as plain arrays. The JSON round trip turns the
     * nested resources into arrays, and it goes through the same encoder as the response itself.
     *
     * @param  iterable<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function toArrays(iterable $products): array
    {
        return json_decode(
            json_encode(ProductResource::collection($products), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function withoutStock(array $items): array
    {
        foreach ($items as $i => $item) {
            $items[$i]['stock'] = null;
        }

        return $items;
    }

    /**
     * Writes the live quantity into the existing "stock" slot. One GetStock call for the whole page,
     * no call for an empty page, and null on every item when the inventory service is unavailable
     * (same rules as ProductService::attachStock, repeated here because that one works on models).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function withLiveStock(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        try {
            $stock = $this->inventory->getStock(array_column($items, 'id'));
        } catch (InventoryUnavailableException) {
            return $this->withoutStock($items);
        }

        foreach ($items as $i => $item) {
            $items[$i]['stock'] = $stock[$item['id']] ?? 0;
        }

        return $items;
    }
}
