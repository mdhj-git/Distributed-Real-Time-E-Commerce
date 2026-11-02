<?php

namespace Modules\Products\Contracts\DTOs;

readonly class StockItem
{
    public function __construct(
        public int $productId,
        public int $quantity,
    ) {}
}
