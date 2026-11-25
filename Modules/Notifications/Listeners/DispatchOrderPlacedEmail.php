<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Jobs\SendOrderEmailJob;
use Modules\Orders\Contracts\Events\OrderPlaced;
use Throwable;

class DispatchOrderPlacedEmail
{
    /** Runs after the order commit, so it must never throw. */
    public function handle(OrderPlaced $event): void
    {
        try {
            SendOrderEmailJob::dispatch(
                $event->userId,
                "Order #{$event->orderId} received",
                "We received your order #{$event->orderId}.",
                "order.{$event->orderId}.placed",
            );
        } catch (Throwable $e) {
            Log::error('Could not queue order email.', [
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
