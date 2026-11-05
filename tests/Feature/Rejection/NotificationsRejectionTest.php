<?php

namespace Tests\Feature\Rejection;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notifications\Models\UserNotification;
use Modules\Users\Contracts\UserRole;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Every way the notifications endpoints must say no.
 */
class NotificationsRejectionTest extends TestCase
{
    use InteractsWithApi;
    use RefreshDatabase;

    private function notificationFor(int $userId): UserNotification
    {
        return UserNotification::create([
            'user_id' => $userId,
            'type' => UserNotification::TYPE_ORDER_PLACED,
            'title' => 'Order placed',
            'message' => 'Your order #1 has been placed.',
            'data' => ['order_id' => 1],
        ]);
    }

    public function test_a_guest_gets_401_on_every_notifications_route(): void
    {
        $this->assertApiError($this->api(null, 'GET', 'notifications'), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->api(null, 'PATCH', 'notifications/1/read'), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->api(null, 'POST', 'notifications/read-all'), 401, 'UNAUTHENTICATED');
    }

    public function test_a_garbage_token_gets_401(): void
    {
        app('auth')->forgetGuards();

        $response = $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/v1/notifications');

        $this->assertApiError($response, 401, 'UNAUTHENTICATED');
    }

    public function test_marking_someone_elses_notification_is_403_and_changes_nothing(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $notification = $this->notificationFor($owner->id);

        $this->assertApiError(
            $this->api($intruder, 'PATCH', "notifications/{$notification->id}/read"),
            403,
            'FORBIDDEN',
        );

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_even_an_admin_cannot_mark_a_customers_notification(): void
    {
        $customer = $this->makeUser();
        $admin = $this->makeUser(UserRole::Admin);
        $notification = $this->notificationFor($customer->id);

        $this->assertApiError(
            $this->api($admin, 'PATCH', "notifications/{$notification->id}/read"),
            403,
            'FORBIDDEN',
        );

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_a_missing_notification_is_404_without_the_model_class_name(): void
    {
        $response = $this->api($this->makeUser(), 'PATCH', 'notifications/999999/read');

        $this->assertApiError($response, 404, 'NOT_FOUND');
        $this->assertStringNotContainsString('UserNotification', $response->getContent());
    }

    public function test_a_non_numeric_id_is_404(): void
    {
        $this->assertApiError($this->api($this->makeUser(), 'PATCH', 'notifications/abc/read'), 404, 'NOT_FOUND');
    }

    public function test_invalid_list_parameters_are_422_with_the_field_in_details(): void
    {
        $user = $this->makeUser();

        foreach (['per_page=0', 'per_page=51', 'per_page=abc', 'page=0'] as $query) {
            $response = $this->api($user, 'GET', "notifications?{$query}");

            $this->assertApiError($response, 422, 'VALIDATION_FAILED');
            $this->assertNotEmpty($response->json('error.details'), $query);
        }

        $this->assertApiError($this->api($user, 'GET', 'notifications?unread=maybe'), 422, 'VALIDATION_FAILED');
    }

    public function test_a_wrong_method_is_405(): void
    {
        $this->assertApiError($this->api($this->makeUser(), 'DELETE', 'notifications'), 405, 'METHOD_NOT_ALLOWED');
    }
}
