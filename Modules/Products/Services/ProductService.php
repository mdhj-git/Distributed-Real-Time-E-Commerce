<?php

namespace Modules\Products\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Products\Contracts\Exceptions\InventoryUnavailableException;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImage;

class ProductService
{
    public function __construct(
        private InventoryService $inventory,
        private CatalogCache $catalogCache,
    ) {}

    /**
     * Uncached on purpose: the write methods below call find() inside transactions to build their
     * responses, so a cached find() could fill the cache with uncommitted data or hand the admin a
     * stale value. The cache of requirement 3 lives in CachedProductReader, which the public read
     * routes use and which calls these two methods on a MISS.
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Product::query()->with(['category', 'images']);

        if (isset($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            // Escape LIKE wildcards so a user cannot turn the term into a pattern.
            $escaped = addcslashes($filters['search'], '\\%_');

            $query->where('name', 'like', '%'.$escaped.'%');
        }

        $paginator = $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));

        $this->attachStock($paginator->getCollection());

        return $paginator;
    }

    public function find(int $id): Product
    {
        $product = Product::query()
            ->with(['category', 'images'])
            ->findOrFail($id);

        $this->attachStock([$product]);

        return $product;
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function create(array $data, array $images = []): Product
    {
        return DB::transaction(function () use ($data, $images) {
            $product = Product::create([
                'category_id' => $data['category_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price' => $data['price'],
            ]);

            $initialStock = (int) ($data['initial_stock'] ?? 0);

            if ($initialStock > 0) {
                $this->inventory->adjust($product->id, $initialStock);
            }

            $this->storeImages($product, $images);

            // Runs after the outermost commit and is dropped on rollback. A new product only
            // changes the lists: no reader can hold a detail entry for an id that did not exist.
            $this->invalidateCatalog(null);

            return $this->find($product->id);
        });
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);
        $this->invalidateCatalog($product->id);

        return $this->find($product->id);
    }

    public function adjustStock(Product $product, int $adjustment): Product
    {
        // No cache invalidation: only the inventory service changes, and stock is never cached.
        $this->inventory->adjust($product->id, $adjustment);

        return $this->find($product->id);
    }

    public function delete(Product $product): void
    {
        // Soft delete: keeps the images and the meaning of old orders intact.
        $product->delete();
        $this->invalidateCatalog($product->id);
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @return Collection<int, ProductImage>
     */
    public function addImages(Product $product, array $images): Collection
    {
        DB::transaction(function () use ($product, $images) {
            $this->storeImages($product, $images);
            $this->invalidateCatalog($product->id);
        });

        return $product->images()->get();
    }

    /**
     * Moves the catalog cache versions once the write is committed. Outside a transaction
     * DB::afterCommit() runs the callback at once, inside one it runs after the outermost commit
     * and is dropped on rollback. Called here and not from model events, because bulk inserts
     * (perf:seed) fire no events.
     */
    private function invalidateCatalog(?int $productId): void
    {
        DB::afterCommit(fn () => $this->catalogCache->invalidateProduct($productId));
    }

    /**
     * Reads stock for the whole page in one call and exposes it on the
     * resource. When the inventory service is down the page still renders and
     * every stock value is null.
     *
     * @param  iterable<int, Product>  $products
     */
    private function attachStock(iterable $products): void
    {
        $products = collect($products);

        if ($products->isEmpty()) {
            return;
        }

        try {
            $stock = $this->inventory->getStock($products->pluck('id')->all());
        } catch (InventoryUnavailableException) {
            foreach ($products as $product) {
                $product->setAttribute('stock', null);
            }

            return;
        }

        foreach ($products as $product) {
            $product->setAttribute('stock', $stock[$product->id] ?? 0);
        }
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    private function storeImages(Product $product, array $images): void
    {
        $position = (int) ($product->images()->max('position') ?? -1) + 1;

        foreach (array_values($images) as $offset => $image) {
            $path = $image->store('products', ProductImage::disk());

            $product->images()->create([
                'path' => $path,
                'position' => $position + $offset,
            ]);
        }
    }
}
