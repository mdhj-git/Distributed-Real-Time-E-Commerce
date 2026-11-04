<?php

namespace Tests\Unit\Products;

use InvalidArgumentException;
use LogicException;
use Modules\Products\Contracts\Exceptions\InsufficientStockException;
use Modules\Products\Contracts\Exceptions\InventoryUnavailableException;
use Modules\Products\Contracts\InventoryService;

/**
 * An in-memory InventoryService for tests only. It is never bound in any provider.
 */
final class FakeInventoryService implements InventoryService
{
    /** @var array<int, int> */
    public array $stock;

    /** @var array<int, array<int, int>> One entry per getStock call: the ids it was asked for. */
    public array $getStockCalls = [];

    /** @var array<int, array{0: int, 1: int}> One entry per successful adjust call: [product id, delta]. */
    public array $adjustCalls = [];

    /** When set, adjust() throws InventoryUnavailableException once this many calls have succeeded. */
    public ?int $failAfterAdjusts = null;

    /**
     * @param  array<int, int>  $stock
     */
    public function __construct(array $stock = [])
    {
        $this->stock = $stock;
    }

    public function getStock(array $productIds): array
    {
        $this->getStockCalls[] = $productIds;

        $result = [];

        foreach ($productIds as $productId) {
            $result[$productId] = $this->stock[$productId] ?? 0;
        }

        return $result;
    }

    public function reserve(string $reference, array $items): void
    {
        throw new LogicException('reserve() is not used by StockSynchronizer.');
    }

    public function release(string $reference, array $items): void
    {
        throw new LogicException('release() is not used by StockSynchronizer.');
    }

    public function adjust(int $productId, int $delta): int
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('adjust() must never be called with a zero delta.');
        }

        if ($this->failAfterAdjusts !== null && count($this->adjustCalls) >= $this->failAfterAdjusts) {
            throw new InventoryUnavailableException();
        }

        $quantity = ($this->stock[$productId] ?? 0) + $delta;

        if ($quantity < 0) {
            throw new InsufficientStockException();
        }

        $this->adjustCalls[] = [$productId, $delta];

        return $this->stock[$productId] = $quantity;
    }
}
