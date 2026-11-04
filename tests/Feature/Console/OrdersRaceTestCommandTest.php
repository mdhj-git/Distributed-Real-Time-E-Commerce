<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * يختبر منطق العدّ والطباعة فقط عبر Http::fake() — لا يعتمد على
 * tests/Concerns/InteractsWithApi.php (فرع الإشعارات) قبل دمجه.
 */
class OrdersRaceTestCommandTest extends TestCase
{
    public function test_it_passes_when_exactly_one_success_and_rest_conflict(): void
    {
        Http::fake([
            'nginx/api/v1/auth/login' => Http::response([
                'data' => ['token' => 'fake-token'],
            ], 200),
            'nginx/api/v1/orders' => Http::sequence()
                ->push(['data' => ['id' => 1]], 201)
                ->push(['error' => ['code' => 'INSUFFICIENT_STOCK']], 409)
                ->push(['error' => ['code' => 'INSUFFICIENT_STOCK']], 409),
            'nginx/api/v1/products/*' => Http::response([
                'data' => ['id' => 1, 'stock' => 0],
            ], 200),
        ]);

        $this->artisan('orders:race-test', ['--product' => 1, '--count' => 3])
            ->expectsOutputToContain('Result: PASS')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_more_than_one_success(): void
    {
        Http::fake([
            'nginx/api/v1/auth/login' => Http::response([
                'data' => ['token' => 'fake-token'],
            ], 200),
            'nginx/api/v1/orders' => Http::sequence()
                ->push(['data' => ['id' => 1]], 201)
                ->push(['data' => ['id' => 2]], 201),
            'nginx/api/v1/products/*' => Http::response([
                'data' => ['id' => 1, 'stock' => 0],
            ], 200),
        ]);

        $this->artisan('orders:race-test', ['--product' => 1, '--count' => 2])
            ->expectsOutputToContain('Result: FAIL')
            ->assertExitCode(1);
    }

    public function test_it_rejects_count_above_maximum(): void
    {
        $this->artisan('orders:race-test', ['--product' => 1, '--count' => 51])
            ->assertExitCode(1);
    }

    public function test_it_requires_product_option(): void
    {
        $this->artisan('orders:race-test', ['--count' => 5])
            ->assertExitCode(1);
    }

    public function test_it_uses_provided_token_without_logging_in(): void
    {
        Http::fake([
            'nginx/api/v1/orders' => Http::response(['data' => ['id' => 1]], 201),
            'nginx/api/v1/products/*' => Http::response(['data' => ['id' => 1, 'stock' => 0]], 200),
        ]);

        $this->artisan('orders:race-test', [
            '--product' => 1,
            '--count' => 1,
            '--token' => 'given-token',
        ])->assertExitCode(0);

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/api/v1/auth/login');
        });
    }
}
