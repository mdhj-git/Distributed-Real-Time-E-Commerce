<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class OrdersRaceTestCommand extends Command
{
    protected $signature = 'orders:race-test
        {--product= : Product ID to reserve concurrently (required)}
        {--count=10 : Number of concurrent order requests (1-50)}
        {--token= : Bearer token to use; if omitted, logs in as demo.customer.a@shop.test}';

    protected $description = 'Send N concurrent POST /api/v1/orders requests for one product '
        .'to prove the stock reservation race condition is handled correctly.';

    private const BASE_URL = 'http://nginx';

    private const MAX_COUNT = 50;

    private const REQUEST_TIMEOUT_SECONDS = 10;

    private const DEMO_EMAIL = 'demo.customer.a@shop.test';

    private const DEMO_PASSWORD = 'password';

    public function handle(): int
    {
        $productId = $this->option('product');

        if (! $productId || ! ctype_digit((string) $productId)) {
            $this->error('The --product option is required and must be a positive integer.');

            return self::FAILURE;
        }

        $count = (int) $this->option('count');

        if ($count < 1 || $count > self::MAX_COUNT) {
            $this->error(sprintf('The --count option must be between 1 and %d.', self::MAX_COUNT));

            return self::FAILURE;
        }

        $token = $this->option('token') ?: $this->loginAsDemoCustomer();

        if ($token === null) {
            $this->error('Could not obtain an auth token. Note: running this command more than '
                .'5 times per minute without --token will hit the login rate limiter.');

            return self::FAILURE;
        }

        $productId = (int) $productId;

        $this->info(sprintf('Sending %d concurrent order requests for product #%d...', $count, $productId));

        $responses = Http::pool(function ($pool) use ($count, $productId, $token) {
            $requests = [];

            for ($i = 0; $i < $count; $i++) {
                $requests[] = $pool
                    ->withToken($token)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->post(self::BASE_URL.'/api/v1/orders', $this->buildOrderPayload($productId));
            }

            return $requests;
        });

        $counts = [];

        foreach ($responses as $index => $response) {
            $status = $this->statusOf($response);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $this->line(sprintf('Request #%d: %s', $index + 1, $status));
        }

        $this->newLine();
        $this->info('Summary:');

        foreach ($counts as $status => $num) {
            $this->line(sprintf('  %s: %d', $status, $num));
        }

        $finalStock = $this->fetchFinalStock($productId);

        $this->newLine();
        $this->info(sprintf(
            'Final stock for product #%d (via GET /api/v1/products/%d): %s',
            $productId,
            $productId,
            $finalStock ?? 'unknown'
        ));

        $successCount = $counts['201'] ?? 0;
        $otherCount = array_sum($counts) - $successCount - ($counts['409'] ?? 0);

        if ($successCount === 1 && $otherCount === 0) {
            $this->info('Result: PASS — exactly one 201 and the rest 409.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Result: FAIL — expected exactly one 201 and the rest 409, got %d success and %d other.',
            $successCount,
            $otherCount
        ));

        return self::FAILURE;
    }

    /**
     * Builds the order request body. Centralized in one place because the
     * Orders module's CreateOrderRequest validation may still change.
     */
    private function buildOrderPayload(int $productId): array
    {
        return [
            'items' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
        ];
    }

    private function statusOf(Response|Throwable $response): string
    {
        if ($response instanceof Throwable) {
            return 'ERROR: '.$response->getMessage();
        }

        return (string) $response->status();
    }

    private function loginAsDemoCustomer(): ?string
    {
        $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->acceptJson()
            ->post(self::BASE_URL.'/api/v1/auth/login', [
                'email' => self::DEMO_EMAIL,
                'password' => self::DEMO_PASSWORD,
            ]);

        if (! $response->successful()) {
            $this->error('Login failed: '.$response->status().' '.$response->body());

            return null;
        }

        return data_get($response->json(), 'data.token');
    }

    private function fetchFinalStock(int $productId): ?int
    {
        $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->acceptJson()
            ->get(self::BASE_URL."/api/v1/products/{$productId}");

        if (! $response->successful()) {
            return null;
        }

        return data_get($response->json(), 'data.stock');
    }
}