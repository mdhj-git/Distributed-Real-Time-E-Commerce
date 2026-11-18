<?php

namespace Modules\Orders\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Orders\Contracts\Events\OrderStatusChanged;
use Modules\Orders\Contracts\Events\OrderStatusChangedBroadcast;
use Throwable;

/**
 * Synchronous on purpose, and it must never throw: see BroadcastOrderPlaced.
 */
class BroadcastOrderStatusChanged
{
    public function handle(OrderStatusChanged $event): void
    {
        try {
            OrderStatusChangedBroadcast::dispatch($event->orderId, $event->to);
        } catch (Throwable $e) {
            Log::error('Could not broadcast the "order status changed" event.', [
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
