<?php

namespace Modules\Products\Contracts;

use Modules\Products\Contracts\DTOs\StockItem;
use Modules\Products\Contracts\Exceptions\InsufficientStockException;

/**
 * Both methods run inside the caller's transaction and rely on it for
 * a full rollback (all or nothing).
 */
interface InventoryService
{
    /**
     * @param  array<int, StockItem>  $items
     *
     * @throws InsufficientStockException
     */
    public function reserve(array $items): void;

    /**
     * @param  array<int, StockItem>  $items
     */
    public function release(array $items): void;
}
