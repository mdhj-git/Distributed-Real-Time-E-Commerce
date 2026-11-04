<?php

namespace Modules\Products\Services;

use Modules\Products\Contracts\DTOs\ProductSnapshot;
use Modules\Products\Contracts\ProductCatalog;
use Modules\Products\Models\Product;
use Modules\Shared\Helpers\Money;

class ProductCatalogService implements ProductCatalog
{
    public function findForOrder(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'price'])
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => new ProductSnapshot(
                    $product->id,
                    $product->name,
                    Money::toCents($product->price),
                ),
            ])
            ->all();
    }
}
