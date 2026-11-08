<?php

namespace Modules\Products\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by StockSynchronizer::syncTo() when the inventory service becomes unavailable halfway.
 *
 * It never crosses the module boundary (only the artisan command and the seeder see it), so it is
 * a plain exception, not an ApiException. The original InventoryUnavailableException is kept as
 * the previous exception, so nothing is swallowed.
 *
 * Everything applied before the stop stays applied, and running the sync again continues from where
 * it stopped, because already-matching products are skipped.
 */
class StockSyncInterruptedException extends RuntimeException
{
    public function __construct(
        public readonly int $total,
        public readonly int $adjusted,
        public readonly int $unchanged,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Stock sync stopped because the inventory service is unavailable: %d of %d products were processed '
                .'(%d adjusted, %d already matched). Nothing was rolled back. Run the same command again to continue.',
                $adjusted + $unchanged,
                $total,
                $adjusted,
                $unchanged,
            ),
            0,
            $previous,
        );
    }
}
