<?php

namespace Modules\Products\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImage;

class ProductService
{
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

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function find(int $id): Product
    {
        return Product::query()
            ->with(['category', 'images'])
            ->findOrFail($id);
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

            $this->storeImages($product, $images);

            return $this->find($product->id);
        });
    }

    public function update(Product $product, array $data): Product
    {
        $product->update($data);

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
     * @param  array<int, UploadedFile>  $images
     */
    private function storeImages(Product $product, array $images): void
    {
        $position = (int) ($product->images()->max('position') ?? -1) + 1;

        foreach (array_values($images) as $offset => $image) {
            $path = $image->store('products', ProductImage::DISK);

            $product->images()->create([
                'path' => $path,
                'position' => $position + $offset,
            ]);
        }
    }
}
