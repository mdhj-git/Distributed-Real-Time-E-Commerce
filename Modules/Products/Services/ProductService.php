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
        private InventoryService $inventory
    ) {}

    /**
     * The single entry point for every product read, so the cache of
     * requirement 3 is added here once and nowhere else.
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

            return $this->find($product->id);
        });
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $this->find($product->id);
    }

    public function adjustStock(Product $product, int $adjustment): Product
    {
        $this->inventory->adjust($product->id, $adjustment);

        return $this->find($product->id);
    }

    public function delete(Product $product): void
    {
        // Soft delete: keeps the images and the meaning of old orders intact.
        $product->delete();
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @return Collection<int, ProductImage>
     */
    public function addImages(Product $product, array $images): Collection
    {
        DB::transaction(fn () => $this->storeImages($product, $images));

        return $product->images()->get();
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
