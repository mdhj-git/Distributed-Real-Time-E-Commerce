<?php

namespace Modules\Products\Contracts;

use Modules\Products\Contracts\DTOs\ProductSnapshot;

interface ProductCatalog
{
    /**
     * Snapshots of the existing, non soft-deleted products only.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, ProductSnapshot> Keyed by product id.
     */
    public function findForOrder(array $productIds): array;
}
