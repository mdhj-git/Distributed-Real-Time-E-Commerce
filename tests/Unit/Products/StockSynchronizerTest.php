<?php

namespace Tests\Unit\Products;

use InvalidArgumentException;
use Modules\Products\Contracts\Exceptions\InventoryUnavailableException;
use Modules\Products\Exceptions\StockSyncInterruptedException;
use Modules\Products\Services\StockSynchronizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryInventoryService;

class StockSynchronizerTest extends TestCase
{
    public function test_running_twice_gives_the_same_result_and_the_second_run_changes_nothing(): void
    {
        $inventory = new InMemoryInventoryService([1 => 10, 2 => 50]);
        $sync = new StockSynchronizer($inventory);
        $targets = [1 => 25, 2 => 20, 3 => 7];

        $first = $sync->syncTo($targets);
        $afterFirst = $inventory->stock;
        $callsAfterFirst = count($inventory->adjustCalls);

        $second = $sync->syncTo($targets);

        $this->assertSame(['total' => 3, 'adjusted' => 3, 'unchanged' => 0], $first);
        $this->assertSame(['total' => 3, 'adjusted' => 0, 'unchanged' => 3], $second);
        $this->assertSame($afterFirst, $inventory->stock);
        $this->assertCount($callsAfterFirst, $inventory->adjustCalls, 'the second run must not call adjust');
        $this->assertSame([1 => 25, 2 => 20, 3 => 7], $inventory->stock);
    }

    public function test_positive_and_negative_differences_and_a_missing_product(): void
    {
        $inventory = new InMemoryInventoryService([1 => 10, 2 => 50, 4 => 0]);
        $sync = new StockSynchronizer($inventory);

        $report = $sync->syncTo([1 => 25, 2 => 20, 3 => 5, 4 => 0]);

        $this->assertSame(['total' => 4, 'adjusted' => 3, 'unchanged' => 1], $report);
        $this->assertSame([[1, 15], [2, -30], [3, 5]], $inventory->adjustCalls);
        $this->assertSame(25, $inventory->stock[1]);
        $this->assertSame(20, $inventory->stock[2]);
        $this->assertSame(5, $inventory->stock[3]);
    }

    public function test_a_target_of_zero_brings_existing_stock_down_to_zero(): void
    {
        $inventory = new InMemoryInventoryService([1 => 9]);

        (new StockSynchronizer($inventory))->syncTo([1 => 0]);

        $this->assertSame([[1, -9]], $inventory->adjustCalls);
        $this->assertSame(0, $inventory->stock[1]);
    }

    public function test_ids_are_split_into_chunks_with_one_get_stock_call_per_chunk(): void
    {
        $inventory = new InMemoryInventoryService;
        $targets = array_fill_keys(range(1, 250), 3);

        $report = (new StockSynchronizer($inventory))->syncTo($targets, 100);

        $this->assertSame(['total' => 250, 'adjusted' => 250, 'unchanged' => 0], $report);
        $this->assertSame([100, 100, 50], array_map('count', $inventory->getStockCalls));
        $this->assertSame(range(1, 100), $inventory->getStockCalls[0]);
        $this->assertSame(range(201, 250), $inventory->getStockCalls[2]);
    }

    public function test_the_default_chunk_size_is_100(): void
    {
        $inventory = new InMemoryInventoryService;

        (new StockSynchronizer($inventory))->syncTo(array_fill_keys(range(1, 101), 1));

        $this->assertSame([100, 1], array_map('count', $inventory->getStockCalls));
    }

    public function test_an_empty_target_list_calls_nothing(): void
    {
        $inventory = new InMemoryInventoryService;

        $report = (new StockSynchronizer($inventory))->syncTo([]);

        $this->assertSame(['total' => 0, 'adjusted' => 0, 'unchanged' => 0], $report);
        $this->assertSame([], $inventory->getStockCalls);
    }

    public function test_it_stops_when_adjust_is_unavailable_keeps_progress_and_a_rerun_continues(): void
    {
        $inventory = new InMemoryInventoryService;
        $sync = new StockSynchronizer($inventory);
        $targets = [1 => 5, 2 => 6, 3 => 7, 4 => 8];

        $inventory->failAdjustOnCall(3);

        try {
            $sync->syncTo($targets);
            $this->fail('Expected the sync to stop.');
        } catch (StockSyncInterruptedException $e) {
            $this->assertSame(4, $e->total);
            $this->assertSame(2, $e->adjusted);
            $this->assertSame(0, $e->unchanged);
            $this->assertInstanceOf(InventoryUnavailableException::class, $e->getPrevious());
            $this->assertStringContainsString('2 of 4', $e->getMessage());
        }

        // Nothing is rolled back and nothing is retried automatically.
        $this->assertSame([1 => 5, 2 => 6], $inventory->stock);
        $this->assertCount(2, $inventory->adjustCalls);

        $inventory->heal();
        $report = $sync->syncTo($targets);

        $this->assertSame(['total' => 4, 'adjusted' => 2, 'unchanged' => 2], $report);
        $this->assertSame([1 => 5, 2 => 6, 3 => 7, 4 => 8], $inventory->stock);
    }

    public function test_it_stops_when_get_stock_is_unavailable_on_a_later_chunk(): void
    {
        $inventory = new InMemoryInventoryService;
        $sync = new StockSynchronizer($inventory);
        $targets = array_fill_keys(range(1, 6), 2);

        $inventory->failGetStockOnCall(2);

        try {
            $sync->syncTo($targets, 3);
            $this->fail('Expected the sync to stop.');
        } catch (StockSyncInterruptedException $e) {
            $this->assertSame(3, $e->adjusted);
            $this->assertInstanceOf(InventoryUnavailableException::class, $e->getPrevious());
        }

        $this->assertSame([1 => 2, 2 => 2, 3 => 2], $inventory->stock);
    }

    public function test_negative_targets_are_rejected_before_any_call(): void
    {
        $inventory = new InMemoryInventoryService;

        try {
            (new StockSynchronizer($inventory))->syncTo([1 => 5, 2 => -1]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('product 2', $e->getMessage());
        }

        $this->assertSame([], $inventory->getStockCalls);
        $this->assertSame([], $inventory->adjustCalls);
        $this->assertSame([], $inventory->stock);
    }

    /**
     * @param  array<mixed>  $targets
     */
    #[DataProvider('invalidTargets')]
    public function test_invalid_targets_are_rejected_before_any_call(array $targets): void
    {
        $inventory = new InMemoryInventoryService;

        $this->expectException(InvalidArgumentException::class);

        try {
            (new StockSynchronizer($inventory))->syncTo($targets);
        } finally {
            $this->assertSame([], $inventory->getStockCalls);
            $this->assertSame([], $inventory->adjustCalls);
        }
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function invalidTargets(): array
    {
        return [
            'float quantity' => [[1 => 1.5]],
            'numeric string quantity' => [[1 => '5']],
            'bool quantity' => [[1 => true]],
            'null quantity' => [[1 => null]],
            'above the service limit' => [[1 => StockSynchronizer::MAX_QUANTITY + 1]],
            'product id zero' => [[0 => 5]],
            'negative product id' => [[-3 => 5]],
            'non numeric product id' => [['abc' => 5]],
        ];
    }

    public function test_invalid_chunk_sizes_are_rejected(): void
    {
        $sync = new StockSynchronizer(new InMemoryInventoryService);

        foreach ([0, -1, StockSynchronizer::MAX_CHUNK_SIZE + 1] as $chunk) {
            try {
                $sync->syncTo([1 => 1], $chunk);
                $this->fail("Chunk size {$chunk} should be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_verify_lists_only_the_products_that_differ_and_never_modifies(): void
    {
        $inventory = new InMemoryInventoryService([1 => 10, 2 => 20]);
        $sync = new StockSynchronizer($inventory);

        $mismatches = $sync->verify([1 => 10, 2 => 99, 3 => 4]);

        $this->assertSame([
            2 => ['expected' => 99, 'actual' => 20],
            3 => ['expected' => 4, 'actual' => 0],
        ], $mismatches);
        $this->assertSame([], $inventory->adjustCalls);
        $this->assertSame([1 => 10, 2 => 20], $inventory->stock);
    }

    public function test_verify_is_empty_after_a_sync(): void
    {
        $inventory = new InMemoryInventoryService([1 => 3]);
        $sync = new StockSynchronizer($inventory);
        $targets = [1 => 8, 2 => 12];

        $sync->syncTo($targets);

        $this->assertSame([], $sync->verify($targets));
    }

    public function test_verify_does_not_swallow_an_unavailable_service(): void
    {
        $inventory = new InMemoryInventoryService;
        $inventory->failGetStockOnCall(1);

        $this->expectException(InventoryUnavailableException::class);

        (new StockSynchronizer($inventory))->verify([1 => 1]);
    }

    public function test_plan_reports_the_deltas_without_modifying(): void
    {
        $inventory = new InMemoryInventoryService([1 => 10, 2 => 5]);

        $plan = (new StockSynchronizer($inventory))->plan([1 => 4, 2 => 5, 3 => 2]);

        $this->assertSame([
            1 => ['current' => 10, 'target' => 4, 'delta' => -6],
            3 => ['current' => 0, 'target' => 2, 'delta' => 2],
        ], $plan);
        $this->assertSame([], $inventory->adjustCalls);
    }
}
