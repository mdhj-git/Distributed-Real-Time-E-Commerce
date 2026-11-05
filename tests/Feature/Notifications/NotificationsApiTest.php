<?php

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notifications\Models\UserNotification;
use Modules\Users\Contracts\UserRole;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    use InteractsWithApi;
    use RefreshDatabase;

    private function notificationFor(int $userId, bool $read = false): UserNotification
    {
        return UserNotification::create([
            'user_id' => $userId,
            'type' => UserNotification::TYPE_ORDER_PLACED,
            'title' => 'Order placed',
            'message' => 'Your order #1 has been placed.',
            'data' => ['order_id' => 1],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_a_user_sees_only_their_own_notifications_newest_first(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();

        $first = $this->notificationFor($me->id);
        $second = $this->notificationFor($me->id);
        $this->notificationFor($other->id);

        $response = $this->api($me, 'GET', 'notifications')->assertOk();

        $this->assertSame([$second->id, $first->id], array_column($response->json('data'), 'id'));
        $this->assertSame(
            ['current_page', 'last_page', 'per_page', 'total'],
            collect(array_keys($response->json('meta')))->sort()->values()->all(),
        );
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_unread_filter_hides_read_notifications(): void
    {
        $me = $this->makeUser();
        $unread = $this->notificationFor($me->id);
        $this->notificationFor($me->id, read: true);

        $response = $this->api($me, 'GET', 'notifications?unread=1')->assertOk();

        $this->assertSame([$unread->id], array_column($response->json('data'), 'id'));
    }

    public function test_an_admin_also_gets_only_their_own_notifications(): void
    {
        $admin = $this->makeUser(UserRole::Admin);
        $customer = $this->makeUser();
        $this->notificationFor($customer->id);

        $this->api($admin, 'GET', 'notifications')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_owner_can_mark_a_notification_as_read(): void
    {
        $me = $this->makeUser();
        $notification = $this->notificationFor($me->id);

        $this->api($me, 'PATCH', "notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_marking_as_read_twice_keeps_the_first_read_time(): void
    {
        $me = $this->makeUser();
        $notification = $this->notificationFor($me->id);

        $this->api($me, 'PATCH', "notifications/{$notification->id}/read")->assertOk();
        $firstReadAt = $notification->fresh()->read_at;

        $this->travel(5)->minutes();
        $this->api($me, 'PATCH', "notifications/{$notification->id}/read")->assertOk();

        $this->assertTrue($firstReadAt->equalTo($notification->fresh()->read_at));
    }

    public function test_read_all_marks_only_my_notifications(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $mine = $this->notificationFor($me->id);
        $theirs = $this->notificationFor($other->id);

        $this->api($me, 'POST', 'notifications/read-all')->assertNoContent();

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }
}
