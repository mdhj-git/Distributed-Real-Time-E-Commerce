<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Contracts\Events\OrderStatusChanged;
use Throwable;

/**
 * Like NotifyOrderPlaced, it must never throw: the status change is already committed when the event
 * is dispatched, so a failure here is only logged and never turns into a 500 for the admin.
 */
class NotifyOrderStatusChanged
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(OrderStatusChanged $event): void
    {
        try {
            $this->notifications->notifyOrderStatusChanged(
                $event->orderId,
                $event->userId,
                $event->from,
                $event->to,
            );
        } catch (Throwable $e) {
            Log::error('Could not create the "order status changed" notification.', [
                'order_id' => $event->orderId,
                'user_id' => $event->userId,
                'from' => $event->from,
                'to' => $event->to,
                'exception' => $e,
            ]);
        }
    }
}
