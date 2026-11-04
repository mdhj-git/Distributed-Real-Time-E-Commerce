<?php

namespace Tests\Support;

use Modules\Products\Contracts\DTOs\StockItem;
use Modules\Products\Contracts\Exceptions\InsufficientStockException;
use Modules\Products\Contracts\Exceptions\InventoryUnavailableException;
use Modules\Products\Contracts\InventoryService;

/**
 * Array-backed InventoryService for unit tests. It is never bound in any service provider.
 * It records every call and can be told to become unavailable on a given call.
 */
class InMemoryInventoryService implements InventoryService
{
    /** @var array<int, array<int, int>> ids of every getStock call, in order */
    public array $getStockCalls = [];

    /** @var array<int, array{0: int, 1: int}> [product id, delta] of every successful adjust call */
    public array $adjustCalls = [];

    private int $getStockCount = 0;

    private int $adjustCount = 0;

    private ?int $failGetStockOnCall = null;

    private ?int $failAdjustOnCall = null;

    /**
     * @param  array<int, int>  $stock  [product_id => quantity]
     */
    public function __construct(public array $stock = []) {}

    /** The n-th (1-based) getStock call, counted from now, throws InventoryUnavailableException. */
    public function failGetStockOnCall(int $n): void
    {
        $this->failGetStockOnCall = $this->getStockCount + $n;
    }

    /** The n-th (1-based) adjust call, counted from now, throws InventoryUnavailableException. */
    public function failAdjustOnCall(int $n): void
    {
        $this->failAdjustOnCall = $this->adjustCount + $n;
    }

    public function heal(): void
    {
        $this->failGetStockOnCall = null;
        $this->failAdjustOnCall = null;
    }

    public function getStock(array $productIds): array
    {
        $this->getStockCount++;

        if ($this->failGetStockOnCall === $this->getStockCount) {
            throw new InventoryUnavailableException;
        }

        $this->getStockCalls[] = $productIds;

        $quantities = [];

        foreach ($productIds as $id) {
            $quantities[$id] = $this->stock[$id] ?? 0;
        }

        return $quantities;
    }

    public function reserve(string $reference, array $items): void
    {
        foreach ($items as $item) {
            /** @var StockItem $item */
            if (($this->stock[$item->productId] ?? 0) < $item->quantity) {
                throw new InsufficientStockException;
            }
        }

        foreach ($items as $item) {
            $this->stock[$item->productId] -= $item->quantity;
        }
    }

    public function release(string $reference, array $items): void
    {
        foreach ($items as $item) {
            $this->stock[$item->productId] = ($this->stock[$item->productId] ?? 0) + $item->quantity;
        }
    }

    public function adjust(int $productId, int $delta): int
    {
        $this->adjustCount++;

        if ($this->failAdjustOnCall === $this->adjustCount) {
            throw new InventoryUnavailableException;
        }

        $new = ($this->stock[$productId] ?? 0) + $delta;

        if ($new < 0) {
            throw new InsufficientStockException;
        }

        $this->stock[$productId] = $new;
        $this->adjustCalls[] = [$productId, $delta];

        return $new;
    }
}
