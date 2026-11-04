<?php

namespace Modules\Products\GrpcClient;

use Google\Protobuf\Internal\Message;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;
use Modules\Products\Grpc\Inventory\V1\AdjustStockRequest;
use Modules\Products\Grpc\Inventory\V1\AdjustStockResponse;
use Modules\Products\Grpc\Inventory\V1\GetStockRequest;
use Modules\Products\Grpc\Inventory\V1\GetStockResponse;
use Modules\Products\Grpc\Inventory\V1\ReleaseStockRequest;
use Modules\Products\Grpc\Inventory\V1\ReleaseStockResponse;
use Modules\Products\Grpc\Inventory\V1\ReserveStockRequest;
use Modules\Products\Grpc\Inventory\V1\ReserveStockResponse;

/**
 * Hand-written gRPC stub for the inventory service.
 *
 * It lives outside Modules/Products/Grpc on purpose: that folder is generated
 * from the .proto file and excluded from Pint, so hand-written code must never
 * be added there. The stub is transport-only: it sends one call with a
 * deadline and returns [response, status]. Mapping status codes to domain
 * exceptions belongs to GrpcInventoryService.
 */
class InventoryClient extends BaseStub
{
    private const SERVICE = '/inventory.v1.Inventory';

    /**
     * @param  string  $host  host:port of the inventory service.
     */
    public function __construct(string $host)
    {
        parent::__construct($host, [
            // No TLS between services in this requirement.
            'credentials' => ChannelCredentials::createInsecure(),
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $metadata
     * @return array{0: GetStockResponse|null, 1: \stdClass}
     */
    public function getStock(GetStockRequest $request, array $metadata, int $timeoutMs): array
    {
        return $this->call('GetStock', $request, [GetStockResponse::class, 'decode'], $metadata, $timeoutMs);
    }

    /**
     * @param  array<string, array<int, string>>  $metadata
     * @return array{0: ReserveStockResponse|null, 1: \stdClass}
     */
    public function reserve(ReserveStockRequest $request, array $metadata, int $timeoutMs): array
    {
        return $this->call('ReserveStock', $request, [ReserveStockResponse::class, 'decode'], $metadata, $timeoutMs);
    }

    /**
     * @param  array<string, array<int, string>>  $metadata
     * @return array{0: ReleaseStockResponse|null, 1: \stdClass}
     */
    public function release(ReleaseStockRequest $request, array $metadata, int $timeoutMs): array
    {
        return $this->call('ReleaseStock', $request, [ReleaseStockResponse::class, 'decode'], $metadata, $timeoutMs);
    }

    /**
     * @param  array<string, array<int, string>>  $metadata
     * @return array{0: AdjustStockResponse|null, 1: \stdClass}
     */
    public function adjust(AdjustStockRequest $request, array $metadata, int $timeoutMs): array
    {
        return $this->call('AdjustStock', $request, [AdjustStockResponse::class, 'decode'], $metadata, $timeoutMs);
    }

    /**
     * @param  array{0: class-string<Message>, 1: string}  $deserialize
     * @param  array<string, array<int, string>>  $metadata
     * @return array{0: Message|null, 1: \stdClass}
     */
    private function call(string $method, Message $request, array $deserialize, array $metadata, int $timeoutMs): array
    {
        $call = $this->_simpleRequest(
            self::SERVICE.'/'.$method,
            $request,
            $deserialize,
            $metadata,
            // gRPC expects microseconds; the config is in milliseconds.
            ['timeout' => $timeoutMs * 1000],
        );

        return $call->wait();
    }
}
