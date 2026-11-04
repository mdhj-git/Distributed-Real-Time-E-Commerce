<?php

namespace Modules\Products\Services;

use Illuminate\Support\Facades\DB;
use Modules\Products\Contracts\DTOs\StockItem;
use Modules\Products\Contracts\Exceptions\InsufficientStockException;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Exceptions\StockShortageSignal;
use Modules\Products\Models\StockLevel;

/**
 * Local implementation of the inventory contract. It is the only class,
 * together with StockLevel, that touches the stock_levels table.
 */
class LocalInventoryService implements InventoryService
{
    /**
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function getStock(array $productIds): array
    {
        return DB::transaction(function () use ($productIds): array {
            if ($productIds === []) {
                return [];
            }

            $stock = StockLevel::query()
                ->whereIn('product_id', array_unique($productIds))
                ->pluck('stock', 'product_id');

            $quantities = [];

            foreach ($productIds as $productId) {
                $quantities[$productId] = (int) ($stock[$productId] ?? 0);
            }

            return $quantities;
        });
    }

    /**
     * @param  array<int, StockItem>  $items
     */
    public function reserve(string $reference, array $items): void
    {
        $requested = [];

        foreach ($items as $item) {
            $requested[$item->productId] = ($requested[$item->productId] ?? 0) + $item->quantity;
        }

        ksort($requested);

        try {
            DB::transaction(function () use ($requested): void {
                foreach ($requested as $productId => $quantity) {
                    $affected = StockLevel::query()
                        ->where('product_id', $productId)
                        ->where('stock', '>=', $quantity)
                        ->decrement('stock', $quantity);

                    if ($affected === 0) {
                        throw new StockShortageSignal;
                    }
                }
            });
        } catch (StockShortageSignal) {
            // The transaction has rolled back, so these are the real quantities.
            $available = $this->getStock(array_keys($requested));

            $shortages = [];

            foreach ($requested as $productId => $quantity) {
                if ($available[$productId] < $quantity) {
                    $shortages[] = [
                        'product_id' => $productId,
                        'requested' => $quantity,
                        'available' => $available[$productId],
                    ];
                }
            }

            throw new InsufficientStockException('Insufficient stock.', ['shortages' => $shortages]);
        }
    }

    /**
     * @param  array<int, StockItem>  $items
     */
    public function release(string $reference, array $items): void
    {
        $quantities = [];

        foreach ($items as $item) {
            $quantities[$item->productId] = ($quantities[$item->productId] ?? 0) + $item->quantity;
        }

        ksort($quantities);

        DB::transaction(function () use ($quantities): void {
            foreach ($quantities as $productId => $quantity) {
                $affected = StockLevel::query()
                    ->where('product_id', $productId)
                    ->increment('stock', $quantity);

                if ($affected === 0) {
                    StockLevel::query()->create([
                        'product_id' => $productId,
                        'stock' => $quantity,
                    ]);
                }
            }
        });
    }

    public function adjust(int $productId, int $delta): int
    {
        return DB::transaction(function () use ($productId, $delta): int {
            if ($delta > 0) {
                StockLevel::query()->firstOrCreate(
                    ['product_id' => $productId],
                    ['stock' => 0],
                );

                StockLevel::query()
                    ->where('product_id', $productId)
                    ->increment('stock', $delta);
            } elseif ($delta < 0) {
                // Never write stock + ? >= 0: on an unsigned column MySQL
                // raises an out-of-range error before evaluating the condition.
                $affected = StockLevel::query()
                    ->where('product_id', $productId)
                    ->where('stock', '>=', abs($delta))
                    ->decrement('stock', abs($delta));

                if ($affected === 0) {
                    throw new InsufficientStockException;
                }
            }

            return (int) StockLevel::query()
                ->where('product_id', $productId)
                ->value('stock');
        });
    }
}
