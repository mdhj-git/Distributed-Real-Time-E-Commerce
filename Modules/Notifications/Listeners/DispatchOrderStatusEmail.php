<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Jobs\SendOrderEmailJob;
use Modules\Orders\Contracts\Events\OrderStatusChanged;
use Throwable;

class DispatchOrderStatusEmail
{
    /** Runs after the order commit, so it must never throw. */
    public function handle(OrderStatusChanged $event): void
    {
        try {
            SendOrderEmailJob::dispatch(
                $event->userId,
                "Order #{$event->orderId} is now {$event->to}",
                "Your order #{$event->orderId} changed from {$event->from} to {$event->to}.",
            );
        } catch (Throwable $e) {
            Log::error('Could not queue order status email.', [
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
