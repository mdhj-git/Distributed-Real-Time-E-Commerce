<?php

namespace Modules\Products\Contracts\DTOs;

readonly class ProductSnapshot
{
    public function __construct(
        public int $id,
        public string $name,
        public int $priceCents,
    ) {}
}
