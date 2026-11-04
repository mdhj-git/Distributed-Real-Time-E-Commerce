<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Contracts\Events\OrderPlaced;
use Throwable;

/**
 * Synchronous on purpose: the split between modules is about knowledge, not timing.
 *
 * It must never throw. OrderPlaced is dispatched after the order has been saved and its stock reserved,
 * so an exception here would turn a successful order into a 500, and the client's retry would create a
 * duplicate order. A failed notification is only logged.
 */
class NotifyOrderPlaced
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(OrderPlaced $event): void
    {
        try {
            $this->notifications->notifyOrderPlaced($event->orderId, $event->userId);
        } catch (Throwable $e) {
            Log::error('Could not create the "order placed" notification.', [
                'order_id' => $event->orderId,
                'user_id' => $event->userId,
                'exception' => $e,
            ]);
        }
    }
}
