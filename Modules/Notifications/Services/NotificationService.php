<?php

namespace Modules\Notifications\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Notifications\Models\UserNotification;

class NotificationService
{
    public function notifyOrderPlaced(int $orderId, int $userId): UserNotification
    {
        return UserNotification::create([
            'user_id' => $userId,
            'type' => UserNotification::TYPE_ORDER_PLACED,
            'title' => 'Order placed',
            'message' => "Your order #{$orderId} has been placed.",
            'data' => ['order_id' => $orderId],
        ]);
    }

    public function notifyOrderStatusChanged(int $orderId, int $userId, string $from, string $to): UserNotification
    {
        return UserNotification::create([
            'user_id' => $userId,
            'type' => UserNotification::TYPE_ORDER_STATUS_CHANGED,
            'title' => 'Order status updated',
            'message' => "Your order #{$orderId} status changed from {$from} to {$to}.",
            'data' => ['order_id' => $orderId, 'from' => $from, 'to' => $to],
        ]);
    }

    /**
     * The query is filtered by owner, so another user's rows are never loaded.
     */
    public function paginateFor(int $userId, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        return UserNotification::query()
            ->where('user_id', $userId)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Idempotent: a notification that is already read keeps its original read_at.
     */
    public function markAsRead(UserNotification $notification): UserNotification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllAsRead(int $userId): int
    {
        return UserNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
