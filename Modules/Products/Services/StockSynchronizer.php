<?php

namespace Modules\Products\Services;

use InvalidArgumentException;
use Modules\Products\Contracts\Exceptions\InsufficientStockException;
use Modules\Products\Contracts\Exceptions\InventoryUnavailableException;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Exceptions\StockSyncInterruptedException;

/**
 * Brings the inventory service to a set of target quantities through the InventoryService contract.
 *
 * InventoryService::adjust() works by difference, so calling it with a target would add the target
 * again on every run. This class reads the current quantity first and adjusts by (target - current),
 * which makes the whole operation idempotent: running it twice gives the same result and the second
 * run changes nothing.
 *
 * It only knows the contract. It does not know gRPC and it does not read any product table.
 *
 * WARNING: for migration time and for the seeder only, with no concurrent order traffic. Reading the
 * quantity and then adjusting it is not atomic with respect to a reservation made at the same moment.
 */
class StockSynchronizer
{
    public const DEFAULT_CHUNK_SIZE = 100;

    /** The inventory service rejects a GetStock call with more product ids than this. */
    public const MAX_CHUNK_SIZE = 1000;

    /** The inventory service rejects quantities and deltas above this (INT32 max). */
    public const MAX_QUANTITY = 2147483647;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Adjusts the service so every product reaches its target quantity.
     *
     * Validation of the whole input happens before the first call to the service.
     *
     * @param  array<int, int>  $targets  [product_id => target quantity]
     * @return array{total: int, adjusted: int, unchanged: int}
     *
     * @throws InvalidArgumentException invalid target, product id or chunk size (nothing was called)
     * @throws StockSyncInterruptedException the service became unavailable midway (progress is in the exception)
     * @throws InsufficientStockException stock changed concurrently
     */
    public function syncTo(array $targets, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $this->validate($targets, $chunkSize);

        $report = ['total' => count($targets), 'adjusted' => 0, 'unchanged' => 0];

        try {
            foreach (array_chunk(array_keys($targets), $chunkSize) as $ids) {
                $current = $this->inventory->getStock($ids);

                foreach ($ids as $id) {
                    $delta = $targets[$id] - (int) ($current[$id] ?? 0);

                    if ($delta === 0) {
                        $report['unchanged']++;

                        continue;
                    }

                    $this->inventory->adjust($id, $delta);
                    $report['adjusted']++;
                }
            }
        } catch (InventoryUnavailableException $e) {
            // Not swallowed and not retried: stop with the progress made so far.
            throw new StockSyncInterruptedException(
                $report['total'],
                $report['adjusted'],
                $report['unchanged'],
                $e,
            );
        }

        return $report;
    }

    /**
     * Shows what syncTo() would do, without changing anything.
     *
     * @param  array<int, int>  $targets  [product_id => target quantity]
     * @return array<int, array{current: int, target: int, delta: int}> only the products that differ, keyed by product id
     *
     * @throws InvalidArgumentException
     * @throws InventoryUnavailableException
     */
    public function plan(array $targets, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $this->validate($targets, $chunkSize);

        $differences = [];

        foreach (array_chunk(array_keys($targets), $chunkSize) as $ids) {
            $current = $this->inventory->getStock($ids);

            foreach ($ids as $id) {
                $quantity = (int) ($current[$id] ?? 0);

                if ($quantity !== $targets[$id]) {
                    $differences[$id] = [
                        'current' => $quantity,
                        'target' => $targets[$id],
                        'delta' => $targets[$id] - $quantity,
                    ];
                }
            }
        }

        return $differences;
    }

    /**
     * Compares the service with the targets and never modifies it.
     *
     * @param  array<int, int>  $targets  [product_id => target quantity]
     * @return array<int, array{expected: int, actual: int}> products that exist in the source but do not
     *                                                     match the service, keyed by product id. Empty means everything matches.
     *
     * @throws InvalidArgumentException
     * @throws InventoryUnavailableException
     */
    public function verify(array $targets, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $mismatches = [];

        foreach ($this->plan($targets, $chunkSize) as $id => $difference) {
            $mismatches[$id] = [
                'expected' => $difference['target'],
                'actual' => $difference['current'],
            ];
        }

        return $mismatches;
    }

    /**
     * @param  array<mixed>  $targets
     *
     * @throws InvalidArgumentException
     */
    private function validate(array $targets, int $chunkSize): void
    {
        if ($chunkSize < 1 || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException(
                sprintf('Chunk size must be between 1 and %d, got %d.', self::MAX_CHUNK_SIZE, $chunkSize)
            );
        }

        foreach ($targets as $productId => $quantity) {
            if (! is_int($productId) || $productId < 1) {
                throw new InvalidArgumentException(
                    sprintf('Product id must be a positive integer, got "%s".', $productId)
                );
            }

            if (! is_int($quantity)) {
                throw new InvalidArgumentException(
                    sprintf('Target quantity for product %d must be an integer.', $productId)
                );
            }

            if ($quantity < 0 || $quantity > self::MAX_QUANTITY) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Target quantity for product %d must be between 0 and %d, got %d.',
                        $productId,
                        self::MAX_QUANTITY,
                        $quantity,
                    )
                );
            }
        }
    }
}
