<?php

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Listeners\NotifyOrderPlaced;
use Modules\Notifications\Listeners\NotifyOrderStatusChanged;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Contracts\Events\OrderPlaced;
use Modules\Orders\Contracts\Events\OrderStatusChanged;
use RuntimeException;
use Tests\TestCase;

class NotificationListenersTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_order_events_have_a_listener_registered(): void
    {
        $this->assertTrue(Event::hasListeners(OrderPlaced::class));
        $this->assertTrue(Event::hasListeners(OrderStatusChanged::class));
    }

    public function test_order_placed_creates_an_unread_notification_for_the_order_owner(): void
    {
        app(NotifyOrderPlaced::class)->handle(new OrderPlaced(orderId: 12, userId: 7));

        $notification = UserNotification::query()->sole();

        $this->assertSame(7, $notification->user_id);
        $this->assertSame(UserNotification::TYPE_ORDER_PLACED, $notification->type);
        $this->assertSame(['order_id' => 12], $notification->data);
        $this->assertNull($notification->read_at);
    }

    public function test_status_change_creates_a_notification_carrying_the_old_and_new_status(): void
    {
        app(NotifyOrderStatusChanged::class)->handle(
            new OrderStatusChanged(orderId: 12, userId: 7, from: 'pending', to: 'confirmed'),
        );

        $notification = UserNotification::query()->sole();

        $this->assertSame(7, $notification->user_id);
        $this->assertSame(UserNotification::TYPE_ORDER_STATUS_CHANGED, $notification->type);
        $this->assertSame(['order_id' => 12, 'from' => 'pending', 'to' => 'confirmed'], $notification->data);
        $this->assertStringContainsString('pending', $notification->message);
        $this->assertStringContainsString('confirmed', $notification->message);
    }

    public function test_dispatching_the_event_creates_exactly_one_notification(): void
    {
        OrderPlaced::dispatch(5, 3);

        $this->assertSame(1, UserNotification::query()->where('user_id', 3)->count());
    }

    public function test_a_failing_order_placed_listener_does_not_throw_and_is_logged(): void
    {
        Log::spy();
        $this->mock(NotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyOrderPlaced')->once()->andThrow(new RuntimeException('database is gone'));
        });

        // The order is already saved when this event fires, so a thrown exception would turn it into a 500.
        OrderPlaced::dispatch(5, 3);

        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_failing_status_changed_listener_does_not_throw_and_is_logged(): void
    {
        Log::spy();
        $this->mock(NotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyOrderStatusChanged')->once()->andThrow(new RuntimeException('database is gone'));
        });

        OrderStatusChanged::dispatch(5, 3, 'pending', 'confirmed');

        Log::shouldHaveReceived('error')->once();
    }
}
