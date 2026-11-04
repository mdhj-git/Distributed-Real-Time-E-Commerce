<?php

namespace Modules\Products\Services;

use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_OK;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Modules\Products\Contracts\DTOs\StockItem;
use Modules\Products\Contracts\Exceptions\InsufficientStockException;
use Modules\Products\Contracts\Exceptions\InventoryUnavailableException;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Grpc\Inventory\V1\AdjustStockRequest;
use Modules\Products\Grpc\Inventory\V1\GetStockRequest;
use Modules\Products\Grpc\Inventory\V1\ReleaseStockRequest;
use Modules\Products\Grpc\Inventory\V1\ReserveStockRequest;
use Modules\Products\Grpc\Inventory\V1\Shortage;
use Modules\Products\Grpc\Inventory\V1\StockLine;
use Modules\Products\GrpcClient\InventoryClient;
use Modules\Shared\Exceptions\ApiException;
use stdClass;
use Throwable;

/**
 * Remote implementation of the inventory contract over gRPC.
 *
 * The class knows gRPC, the rest of Laravel only knows the contract. Every
 * call has a deadline, and every failure is logged with its real gRPC code
 * and message before a domain exception is thrown, because ExceptionRenderer
 * deliberately does not log ApiException instances.
 */
class GrpcInventoryService implements InventoryService
{
    /**
     * The best-effort release after a reserve timeout must not spend the full
     * request budget again.
     */
    private const COMPENSATION_TIMEOUT_MS = 500;

    private InventoryClient $client;

    private int $timeoutMs;

    public function __construct(?InventoryClient $client = null)
    {
        $config = config('inventory.grpc');

        $this->client = $client ?? new InventoryClient(
            sprintf('%s:%d', $config['host'], (int) $config['port']),
        );
        $this->timeoutMs = (int) $config['timeout_ms'];
    }

    public function getStock(array $productIds): array
    {
        // The contract treats an empty list as a valid empty result. The
        // service would reject it, so it must never reach the wire.
        if ($productIds === []) {
            return [];
        }

        $request = new GetStockRequest;
        $request->setProductIds(array_values($productIds));

        [$response, $status] = $this->call(
            'GetStock',
            fn () => $this->client->getStock($request, $this->metadata(), $this->timeoutMs),
        );
        $this->assertOk('GetStock', $status);

        $quantities = array_fill_keys(array_map('intval', $productIds), 0);

        foreach ($response->getItems() as $line) {
            $quantities[(int) $line->getProductId()] = (int) $line->getQuantity();
        }

        return $quantities;
    }

    public function reserve(string $reference, array $items): void
    {
        $request = new ReserveStockRequest;
        $request->setReference($reference);
        $request->setItems(array_map(
            fn (StockItem $item) => (new StockLine)
                ->setProductId($item->productId)
                ->setQuantity($item->quantity),
            $items,
        ));

        [$response, $status] = $this->call(
            'ReserveStock',
            fn () => $this->client->reserve($request, $this->metadata(), $this->timeoutMs),
        );

        // A timeout is the only case where the reservation may have succeeded
        // without us receiving the answer, so release it before giving up.
        if ($status->code === STATUS_DEADLINE_EXCEEDED) {
            $this->logFailure('ReserveStock', $status);
            $this->compensate($reference);

            throw new InventoryUnavailableException;
        }

        $this->assertOk('ReserveStock', $status);

        if (! $response->getReserved()) {
            $shortages = $this->shortages($response->getShortages());

            if ($shortages === []) {
                // Replaying a reference that was already released comes back as
                // reserved=false with no shortages. That is not a stock
                // shortage but an unexpected state, so it must log and 503
                // exactly like any other unexpected service answer.
                $this->logFailure('ReserveStock', $status, 'reserved=false without shortages');

                throw new InventoryUnavailableException;
            }

            throw new InsufficientStockException('Insufficient stock.', [
                'shortages' => $shortages,
            ]);
        }
    }

    public function release(string $reference, array $items): void
    {
        // $items is deliberately ignored: the remote service remembers what it
        // reserved for this reference. The contract keeps $items for the local
        // implementation only.
        $request = new ReleaseStockRequest;
        $request->setReference($reference);

        [, $status] = $this->call(
            'ReleaseStock',
            fn () => $this->client->release($request, $this->metadata(), $this->timeoutMs),
        );
        $this->assertOk('ReleaseStock', $status);
    }

    public function adjust(int $productId, int $delta): int
    {
        $request = new AdjustStockRequest;
        $request->setProductId($productId);
        $request->setDelta($delta);

        [$response, $status] = $this->call(
            'AdjustStock',
            fn () => $this->client->adjust($request, $this->metadata(), $this->timeoutMs),
        );
        $this->assertOk('AdjustStock', $status);

        if (! $response->getApplied()) {
            throw new InsufficientStockException('Insufficient stock.', [
                'product_id' => $productId,
                'adjustment' => $delta,
            ]);
        }

        return (int) $response->getQuantity();
    }

    /**
     * @template T
     *
     * @param  callable(): array{0: T|null, 1: stdClass}  $call
     * @return array{0: T|null, 1: stdClass}
     */
    private function call(string $operation, callable $call): array
    {
        try {
            return $call();
        } catch (Throwable $e) {
            // A transport failure (for example a channel error) still means
            // the service is unavailable from the caller's point of view.
            $this->logFailure($operation, null, $e->getMessage());

            throw new InventoryUnavailableException;
        }
    }

    private function assertOk(string $operation, stdClass $status): void
    {
        if ($status->code === STATUS_OK) {
            return;
        }

        $this->logFailure($operation, $status);

        if ($status->code === STATUS_INVALID_ARGUMENT) {
            // Invalid arguments can only come from a bug in this adapter, so
            // they must surface as a server error, never as a user error.
            throw new ApiException(500, 'SERVER_ERROR', 'An unexpected error occurred.');
        }

        // UNAVAILABLE, DEADLINE_EXCEEDED, FAILED_PRECONDITION, INTERNAL and
        // any unknown code: the caller only needs a clear "try later" answer.
        throw new InventoryUnavailableException;
    }

    private function compensate(string $reference): void
    {
        try {
            $request = new ReleaseStockRequest;
            $request->setReference($reference);

            [, $status] = $this->client->release(
                $request,
                $this->metadata(),
                min($this->timeoutMs, self::COMPENSATION_TIMEOUT_MS),
            );

            if ($status->code !== STATUS_OK) {
                $this->logFailure('ReleaseStock (compensation)', $status);
            }
        } catch (Throwable $e) {
            $this->logFailure('ReleaseStock (compensation)', null, $e->getMessage());
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function metadata(): array
    {
        $requestId = Context::get('request_id');

        return is_string($requestId) && $requestId !== ''
            ? ['x-request-id' => [$requestId]]
            : [];
    }

    private function logFailure(string $operation, ?stdClass $status, ?string $message = null): void
    {
        Log::error('inventory grpc call failed', [
            'operation' => $operation,
            'grpc_code' => $status?->code,
            'grpc_message' => $message ?? $status?->details,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * @param  iterable<int, Shortage>  $shortages
     * @return array<int, array{product_id: int, requested: int, available: int}>
     */
    private function shortages(iterable $shortages): array
    {
        $details = [];

        foreach ($shortages as $shortage) {
            $details[] = [
                'product_id' => (int) $shortage->getProductId(),
                'requested' => (int) $shortage->getRequested(),
                'available' => (int) $shortage->getAvailable(),
            ];
        }

        return $details;
    }
}
