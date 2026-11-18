<?php

namespace Modules\Orders\Contracts\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sent to the admin dashboard when an order has been placed.
 *
 * ShouldBroadcastNow: it is sent while the request runs, never through the queue, so live updates do not
 * depend on the worker. The payload holds ids and status only; the page fetches details from /api/v1.
 */
class OrderPlacedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $status,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.orders')];
    }

    public function broadcastAs(): string
    {
        return 'order.placed';
    }

    /**
     * @return array{order_id: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status];
    }
}
