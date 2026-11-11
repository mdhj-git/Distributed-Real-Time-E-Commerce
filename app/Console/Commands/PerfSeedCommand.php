<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Orders\Enums\OrderStatus;
use Modules\Products\Services\StockSynchronizer;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

/**
 * Deterministic bulk data for load tests (Requirement 3). Not called from DatabaseSeeder or entrypoint.sh.
 *
 * Correct sequence: migrate:fresh --seed --force, then perf:seed.
 *
 * Fake orders are inserted straight into the application database and have NO reservation in the
 * inventory service. They must never be cancelled (cancel would call release on an unknown reference).
 */
class PerfSeedCommand extends Command
{
    protected $signature = 'perf:seed
        {--seed=42 : Seed of the private random generator}
        {--customers=2000 : Number of fake customers}
        {--products=5000 : Number of fake products}
        {--orders=300000 : Number of fake orders}
        {--batch=2000 : Rows per INSERT statement (1000-5000)}
        {--stock-qty=1000000 : Inventory quantity set for every fake product}
        {--reference-date=2026-01-01 : Fixed "now" used for all dates (UTC)}';

    protected $description = 'Generate a deterministic fake dataset (customers, products, images, orders) for k6 load tests.';

    private const FIRST_CUSTOMER_EMAIL = 'perf.customer.1@shop.test';

    private const PASSWORD = 'password';

    private const ZIPF_EXPONENT = 0.8;

    /** Status weights in percent. Only values defined by the Orders OrderStatus enum. */
    private const STATUS_WEIGHTS = [
        'delivered' => 80,
        'shipped' => 6,
        'confirmed' => 5,
        'pending' => 5,
        'cancelled' => 4,
    ];

    private const ADJECTIVES = ['Premium', 'Classic', 'Compact', 'Ultra', 'Smart', 'Eco', 'Pro', 'Essential', 'Deluxe', 'Portable'];

    /** Nouns per category slug. Search terms in perf/k6/lib.js are taken from these lists. */
    private const NOUNS = [
        'electronics' => ['Headphones', 'Keyboard', 'Speaker', 'Charger', 'Monitor', 'Camera'],
        'books' => ['Novel', 'Cookbook', 'Atlas', 'Journal', 'Guide', 'Dictionary'],
        'clothing' => ['Jacket', 'Sneakers', 'Backpack', 'Sweater', 'Scarf', 'Gloves'],
    ];

    private Randomizer $rng;

    /** @var array<string, float> phase name => seconds */
    private array $timings = [];

    public function handle(): int
    {
        $seed = (int) $this->option('seed');
        $customers = (int) $this->option('customers');
        $products = (int) $this->option('products');
        $orders = (int) $this->option('orders');
        $batch = (int) $this->option('batch');
        $stockQty = (int) $this->option('stock-qty');
        $referenceTs = strtotime($this->option('reference-date').' 00:00:00 UTC');

        if ($customers < 60 || $products < 10 || $orders < 1 || $batch < 1000 || $batch > 5000 || $stockQty < 1 || $stockQty > StockSynchronizer::MAX_QUANTITY || $referenceTs === false) {
            $this->error('Invalid options: need --customers>=60, --products>=10, --orders>=1, --batch 1000-5000, --stock-qty 1..'.StockSynchronizer::MAX_QUANTITY.', a valid --reference-date.');

            return self::FAILURE;
        }

        if (DB::table('users')->where('email', self::FIRST_CUSTOMER_EMAIL)->exists()) {
            $this->error('Fake data already exists ('.self::FIRST_CUSTOMER_EMAIL.' is present). '
                .'Correct sequence: php artisan migrate:fresh --seed --force, then php artisan perf:seed.');

            return self::FAILURE;
        }

        $categoryIds = DB::table('categories')->whereIn('slug', array_keys(self::NOUNS))->pluck('id', 'slug')->all();

        if (count($categoryIds) !== count(self::NOUNS)) {
            $this->error('The 3 categories from db:seed (electronics, books, clothing) are missing. Run migrate:fresh --seed --force first.');

            return self::FAILURE;
        }

        DB::connection()->disableQueryLog();
        $this->rng = new Randomizer(new Mt19937($seed));
        $total = microtime(true);

        $firstUserId = (int) DB::table('users')->max('id') + 1;
        $firstProductId = (int) DB::table('products')->max('id') + 1;
        $firstOrderId = (int) DB::table('orders')->max('id') + 1;

        $this->info(sprintf('perf:seed seed=%d customers=%d products=%d orders=%d batch=%d', $seed, $customers, $products, $orders, $batch));

        $this->seedCustomers($customers, $firstUserId, $referenceTs, $batch);
        $catalog = $this->seedProducts($products, $firstProductId, $referenceTs, $batch, $categoryIds);
        $this->seedImages($products, $firstProductId, $referenceTs, $batch);
        $this->syncStock($firstProductId, $products, $stockQty);

        $orderCounts = $this->seedOrders($orders, $customers, $firstUserId, $firstOrderId, $firstProductId, $catalog, $referenceTs, $batch);

        foreach (['users' => $firstUserId + $customers, 'products' => $firstProductId + $products, 'orders' => $firstOrderId + $orders] as $table => $next) {
            DB::statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.$next);
        }

        $this->writeDataset($seed, $customers, $firstUserId, $firstProductId, $products, $firstOrderId, $orders, $orderCounts);

        $this->newLine();
        $this->info('Phase timings (seconds):');
        foreach ($this->timings as $phase => $seconds) {
            $this->line(sprintf('  %-34s %8.2f', $phase, $seconds));
        }
        $this->line(sprintf('  %-34s %8.2f', 'TOTAL', microtime(true) - $total));
        $this->line('  (insert phases for index write-cost comparison: customers, products, images, orders insert, order_items insert; NOT stock sync)');

        $this->warn('Fake orders have no inventory reservation: never cancel them.');

        return self::SUCCESS;
    }

    private function seedCustomers(int $count, int $firstId, int $referenceTs, int $batch): void
    {
        $start = microtime(true);
        $hash = Hash::make(self::PASSWORD); // computed once, shared by all fake customers
        $span = 730 * 86400;
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $created = gmdate('Y-m-d H:i:s', $referenceTs - $span + $this->rng->getInt(0, $span - 1));
            $rows[] = [
                'id' => $firstId + $i - 1,
                'name' => 'Perf Customer '.$i,
                'email' => 'perf.customer.'.$i.'@shop.test',
                'password' => $hash,
                'role' => 'customer',
                'created_at' => $created,
                'updated_at' => $created,
            ];

            if (count($rows) >= $batch) {
                DB::table('users')->insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            DB::table('users')->insert($rows);
        }

        $this->timings['customers'] = microtime(true) - $start;
        $this->line('customers done');
    }

    /**
     * @param  array<string, int>  $categoryIds
     * @return array{names: list<string>, cents: list<int>}
     */
    private function seedProducts(int $count, int $firstId, int $referenceTs, int $batch, array $categoryIds): array
    {
        $start = microtime(true);
        $slugs = array_keys(self::NOUNS);
        $names = [];
        $cents = [];
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $slug = $slugs[$this->rng->getInt(0, count($slugs) - 1)];
            $nouns = self::NOUNS[$slug];
            $name = self::ADJECTIVES[$this->rng->getInt(0, count(self::ADJECTIVES) - 1)].' '
                .$nouns[$this->rng->getInt(0, count($nouns) - 1)].' '.$this->rng->getInt(100, 999);
            $price = $this->rng->getInt(199, 49999);
            $created = gmdate('Y-m-d H:i:s', $referenceTs - $this->rng->getInt(0, 730 * 86400 - 1));

            $names[] = $name;
            $cents[] = $price;
            $rows[] = [
                'id' => $firstId + $i,
                'category_id' => $categoryIds[$slug],
                'name' => $name,
                'description' => 'Perf dataset item '.($i + 1).' in '.$slug.'.',
                'price' => $this->money($price),
                'created_at' => $created,
                'updated_at' => $created,
            ];

            if (count($rows) >= $batch) {
                DB::table('products')->insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            DB::table('products')->insert($rows);
        }

        $this->timings['products'] = microtime(true) - $start;
        $this->line('products done');

        return ['names' => $names, 'cents' => $cents];
    }

    private function seedImages(int $products, int $firstProductId, int $referenceTs, int $batch): void
    {
        $start = microtime(true);
        $now = gmdate('Y-m-d H:i:s', $referenceTs);
        $rows = [];

        for ($i = 0; $i < $products; $i++) {
            $productId = $firstProductId + $i;
            $n = $this->rng->getInt(0, 3);

            for ($pos = 0; $pos < $n; $pos++) {
                // Placeholder paths only: no real files exist.
                $rows[] = [
                    'product_id' => $productId,
                    'path' => 'products/perf-'.$productId.'-'.$pos.'.jpg',
                    'position' => $pos,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($rows) >= $batch) {
                DB::table('product_images')->insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            DB::table('product_images')->insert($rows);
        }

        $this->timings['product images'] = microtime(true) - $start;
        $this->line('product images done');
    }

    private function syncStock(int $firstProductId, int $products, int $qty): void
    {
        $start = microtime(true);
        $targets = [];

        for ($i = 0; $i < $products; $i++) {
            $targets[$firstProductId + $i] = $qty;
        }

        try {
            $result = app(StockSynchronizer::class)->syncTo($targets, StockSynchronizer::MAX_CHUNK_SIZE);
            $this->line('stock sync: '.json_encode($result));
        } catch (Throwable $e) {
            $this->error('Stock sync failed ('.get_class($e).'): '.$e->getMessage());
            $this->warn('Rows are already inserted. Reset with migrate:fresh --seed --force and run perf:seed again.');
            throw $e;
        }

        $this->timings['stock sync (gRPC, excluded)'] = microtime(true) - $start;
    }

    /**
     * @param  array{names: list<string>, cents: list<int>}  $catalog
     * @return array<int, int> customer index (1-based) => number of orders
     */
    private function seedOrders(int $orders, int $customers, int $firstUserId, int $firstOrderId, int $firstProductId, array $catalog, int $referenceTs, int $batch): array
    {
        // Skewed (Zipf-like) customer weights: customer 1 is the heaviest.
        $cumulative = [];
        $sum = 0.0;
        for ($i = 1; $i <= $customers; $i++) {
            $sum += 1.0 / ($i ** self::ZIPF_EXPONENT);
            $cumulative[] = $sum;
        }

        $statusValues = [];
        foreach (self::STATUS_WEIGHTS as $value => $weight) {
            // Guard: the value must exist in the real enum.
            $statusValues = array_merge($statusValues, array_fill(0, $weight, OrderStatus::from($value)->value));
        }

        $productCount = count($catalog['names']);
        $baseProductId = $firstProductId; // fake product IDs are sequential from here

        $span = 730 * 86400;
        $start = $referenceTs - $span;
        $step = intdiv($span, $orders) + 1;
        $seenRefs = [];
        $counts = [];
        $orderRows = [];
        $itemRows = [];
        $generate = 0.0;
        $insertOrders = 0.0;
        $insertItems = 0.0;
        $done = 0;

        $flush = function () use (&$orderRows, &$itemRows, &$insertOrders, &$insertItems): void {
            $t = microtime(true);
            DB::table('orders')->insert($orderRows);
            $insertOrders += microtime(true) - $t;

            // order_items are inserted in chunks that respect the placeholder limit.
            foreach (array_chunk($itemRows, 3000) as $chunk) {
                $t = microtime(true);
                DB::table('order_items')->insert($chunk);
                $insertItems += microtime(true) - $t;
            }

            $orderRows = [];
            $itemRows = [];
        };

        for ($i = 0; $i < $orders; $i++) {
            $g = microtime(true);
            $orderId = $firstOrderId + $i;

            $u = $this->rng->getInt(1, 1_000_000_000) / 1_000_000_000 * $sum;
            $lo = 0;
            $hi = $customers - 1;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if ($cumulative[$mid] < $u) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            $customerIndex = $lo + 1;
            $counts[$customerIndex] = ($counts[$customerIndex] ?? 0) + 1;

            $itemCount = $this->rng->getInt(1, 4);
            $picked = [];
            while (count($picked) < $itemCount) {
                $picked[$this->rng->getInt(0, $productCount - 1)] = true;
            }

            $totalCents = 0;
            foreach (array_keys($picked) as $p) {
                $qty = $this->rng->getInt(1, 3);
                $totalCents += $catalog['cents'][$p] * $qty;
                $itemRows[] = [
                    'order_id' => $orderId,
                    'product_id' => $baseProductId + $p,
                    'product_name' => $catalog['names'][$p],
                    'unit_price' => $this->money($catalog['cents'][$p]),
                    'quantity' => $qty,
                    'created_at' => null,
                    'updated_at' => null,
                ];
            }

            $created = gmdate('Y-m-d H:i:s', $start + $i * $step + $this->rng->getInt(0, $step - 1));
            // Backfill the item timestamps now that the order date is known.
            for ($k = count($itemRows) - count($picked); $k < count($itemRows); $k++) {
                $itemRows[$k]['created_at'] = $created;
                $itemRows[$k]['updated_at'] = $created;
            }

            do {
                $b = $this->rng->getBytes(16);
                $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
                $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
                $h = bin2hex($b);
                $reference = substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12);
            } while (isset($seenRefs[$reference]));
            $seenRefs[$reference] = true;

            $orderRows[] = [
                'id' => $orderId,
                'reference' => $reference,
                'user_id' => $firstUserId + $customerIndex - 1,
                'status' => $statusValues[$this->rng->getInt(0, count($statusValues) - 1)],
                'total' => $this->money($totalCents),
                'created_at' => $created,
                'updated_at' => $created,
            ];
            $generate += microtime(true) - $g;

            if (count($orderRows) >= $batch) {
                $flush();
            }

            $done++;
            if ($done % 50000 === 0) {
                $this->line(sprintf('orders %d / %d', $done, $orders));
            }
        }

        if ($orderRows) {
            $flush();
        }

        $this->timings['orders: generation (CPU, PHP)'] = $generate;
        $this->timings['orders: INSERT'] = $insertOrders;
        $this->timings['order_items: INSERT'] = $insertItems;
        $this->line('orders done');

        return $counts;
    }

    /**
     * @param  array<int, int>  $orderCounts
     */
    private function writeDataset(int $seed, int $customers, int $firstUserId, int $firstProductId, int $products, int $firstOrderId, int $orders, array $orderCounts): void
    {
        // Fixed k6 customer set: 10 heaviest, 20 around the median, 20 lightest (with at least one order).
        $ranked = [];
        foreach ($orderCounts as $index => $n) {
            $ranked[] = ['index' => $index, 'orders' => $n];
        }
        usort($ranked, fn ($a, $b) => [$b['orders'], $a['index']] <=> [$a['orders'], $b['index']]);

        $total = count($ranked);
        $heavy = array_slice($ranked, 0, 10);
        $light = array_slice($ranked, $total - 20, 20);
        $midStart = intdiv($total, 2) - 10;
        $mid = array_slice($ranked, $midStart, 20);

        $shape = fn (array $group, string $kind): array => array_map(fn ($c) => [
            'id' => $firstUserId + $c['index'] - 1,
            'email' => 'perf.customer.'.$c['index'].'@shop.test',
            'orders' => $c['orders'],
            'kind' => $kind,
        ], $group);

        $set = array_merge($shape($heavy, 'heavy'), $shape($mid, 'mid'), $shape($light, 'light'));

        $payload = [
            'seed' => $seed,
            'password' => self::PASSWORD,
            'admin_email' => 'demo.admin@shop.test',
            'customers_total' => $customers,
            'products' => ['first_id' => $firstProductId, 'last_id' => $firstProductId + $products - 1],
            'orders' => ['first_id' => $firstOrderId, 'last_id' => $firstOrderId + $orders - 1, 'total' => $orders],
            'k6_customers' => $set,
        ];

        $path = base_path('perf/k6/customers.json');
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->line('wrote perf/k6/customers.json ('.count($set).' customers: 10 heavy, 20 mid, 20 light)');
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
