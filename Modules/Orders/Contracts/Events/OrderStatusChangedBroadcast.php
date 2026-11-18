<?php

namespace Modules\Orders\Contracts\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sent to the owner's tracking page and to the admin dashboard when an order's status changes.
 *
 * ShouldBroadcastNow: see OrderPlacedBroadcast. The payload holds ids and status only.
 */
class OrderStatusChangedBroadcast implements ShouldBroadcastNow
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
        return [
            new PrivateChannel('orders.'.$this->orderId),
            new PrivateChannel('admin.orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    /**
     * @return array{order_id: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status];
    }
}
