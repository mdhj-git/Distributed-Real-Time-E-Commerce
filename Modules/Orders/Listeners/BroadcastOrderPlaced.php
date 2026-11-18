<?php

namespace Modules\Orders\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Orders\Contracts\Events\OrderPlaced;
use Modules\Orders\Contracts\Events\OrderPlacedBroadcast;
use Modules\Orders\Enums\OrderStatus;
use Throwable;

/**
 * Synchronous on purpose, and it must never throw: OrderPlaced is dispatched after the order has been saved
 * and its stock reserved, so a Reverb failure must not turn a successful order into a 500 (the client's
 * retry would create a duplicate). A failed broadcast is only logged.
 */
class BroadcastOrderPlaced
{
    public function handle(OrderPlaced $event): void
    {
        try {
            // A new order is always pending.
            OrderPlacedBroadcast::dispatch($event->orderId, OrderStatus::Pending->value);
        } catch (Throwable $e) {
            Log::error('Could not broadcast the "order placed" event.', [
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
