<?php

namespace Modules\Products\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Products\Contracts\Exceptions\InsufficientStockException;
use Modules\Products\Contracts\Exceptions\InventoryUnavailableException;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Exceptions\StockSyncInterruptedException;
use Modules\Products\Models\StockLevel;
use Modules\Products\Services\StockSynchronizer;

/**
 * Moves the quantities of the old stock_levels table into the inventory service, through the
 * InventoryService contract. Idempotent: running it twice gives the same result.
 *
 * Run it BEFORE the migration that drops stock_levels (order of commands: see README).
 * Migration time only: do not run it while orders are being placed.
 */
class ImportStockCommand extends Command
{
    /** Class name as a string on purpose: the local implementation is deleted at the end of this work. */
    private const LOCAL_IMPLEMENTATION = 'Modules\\Products\\Services\\LocalInventoryService';

    protected $signature = 'inventory:import-stock
        {--dry-run : Show what would change, without changing anything}
        {--verify : Only compare stock_levels with the inventory service; exit 1 on any difference}
        {--chunk=100 : Products per GetStock call (1-1000)}';

    protected $description = 'Import stock_levels quantities into the inventory service (idempotent)';

    public function handle(InventoryService $inventory, StockSynchronizer $synchronizer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verify = (bool) $this->option('verify');

        if ($dryRun && $verify) {
            $this->error('Use either --dry-run or --verify, not both.');

            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);

        if ($chunk === false) {
            $this->error('--chunk must be an integer between 1 and '.StockSynchronizer::MAX_CHUNK_SIZE.'.');

            return self::FAILURE;
        }

        if (is_a($inventory, self::LOCAL_IMPLEMENTATION)) {
            // Source and destination would be the same table: a "successful" run would prove nothing.
            $this->error('InventoryService is still bound to the local implementation, so there is nothing to import into.');
            $this->line('Bind the gRPC implementation first (and start the inventory service), then run this command again.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('stock_levels')) {
            $this->error('The stock_levels table does not exist. It was already removed, so there is nothing to import.');

            return self::FAILURE;
        }

        $targets = $this->loadTargets();

        if ($targets === []) {
            $this->info('stock_levels is empty: nothing to import.');

            return self::SUCCESS;
        }

        try {
            if ($dryRun) {
                return $this->dryRun($synchronizer, $targets, $chunk);
            }

            if ($verify) {
                return $this->verify($synchronizer, $targets, $chunk);
            }

            return $this->import($synchronizer, $targets, $chunk);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('Nothing was sent to the inventory service.');

            return self::FAILURE;
        } catch (StockSyncInterruptedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (InventoryUnavailableException) {
            $this->error('The inventory service is unavailable (stopped or too slow). Nothing was changed by this run. Start it and try again.');

            return self::FAILURE;
        } catch (InsufficientStockException) {
            $this->error('A quantity changed while the import was running, which made an adjustment impossible. Stop order traffic and run the command again.');

            return self::FAILURE;
        }
    }

    /**
     * The only place that reads the source. The names (product_id, stock) are the ones of
     * the create_stock_levels_table migration.
     *
     * @return array<int, int> [product_id => stock]
     */
    private function loadTargets(): array
    {
        $targets = [];

        foreach (StockLevel::query()->orderBy('product_id')->pluck('stock', 'product_id') as $productId => $stock) {
            $targets[(int) $productId] = (int) $stock;
        }

        return $targets;
    }

    /**
     * @param  array<int, int>  $targets
     */
    private function import(StockSynchronizer $synchronizer, array $targets, int $chunk): int
    {
        $report = $synchronizer->syncTo($targets, $chunk);

        $this->summary($report['total'], 'Adjusted', $report['adjusted'], 'Unchanged', $report['unchanged']);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $targets
     */
    private function dryRun(StockSynchronizer $synchronizer, array $targets, int $chunk): int
    {
        $plan = $synchronizer->plan($targets, $chunk);

        if ($plan !== []) {
            $this->table(
                ['product_id', 'current', 'target', 'delta'],
                array_map(
                    fn (int $id, array $row): array => [$id, $row['current'], $row['target'], sprintf('%+d', $row['delta'])],
                    array_keys($plan),
                    $plan,
                ),
            );
        }

        $this->line('Dry run: nothing was changed.');
        $this->summary(count($targets), 'Would adjust', count($plan), 'Unchanged', count($targets) - count($plan));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $targets
     */
    private function verify(StockSynchronizer $synchronizer, array $targets, int $chunk): int
    {
        $mismatches = $synchronizer->verify($targets, $chunk);

        if ($mismatches !== []) {
            $this->table(
                ['product_id', 'stock_levels', 'inventory service'],
                array_map(
                    fn (int $id, array $row): array => [$id, $row['expected'], $row['actual']],
                    array_keys($mismatches),
                    $mismatches,
                ),
            );
        }

        $this->summary(count($targets), 'Mismatched', count($mismatches), 'Matching', count($targets) - count($mismatches));

        if ($mismatches !== []) {
            $this->error('Verification failed: do not drop stock_levels.');

            return self::FAILURE;
        }

        $this->info('Verification passed: the inventory service matches stock_levels.');

        return self::SUCCESS;
    }

    private function summary(int $total, string $firstLabel, int $first, string $secondLabel, int $second): void
    {
        $this->line("Products: {$total}");
        $this->line("{$firstLabel}: {$first}");
        $this->line("{$secondLabel}: {$second}");
    }
}
