<?php

namespace Tests\Feature\Rejection;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Modules\Users\Contracts\UserRole;
use Tests\Concerns\InteractsWithApi;
use Tests\TestCase;

/**
 * Rejections that happen in route middleware, before any controller (and so any other module) runs:
 * 401 for guests, 403 for the wrong role, and the 404/405 envelope for routes that do not exist.
 */
class RouteAccessRejectionTest extends TestCase
{
    use InteractsWithApi;
    use RefreshDatabase;

    public function test_a_guest_gets_401_on_the_protected_orders_routes(): void
    {
        $this->assertApiError($this->api(null, 'GET', 'orders'), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->api(null, 'POST', 'orders', ['items' => []]), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->api(null, 'GET', 'orders/1'), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->api(null, 'PATCH', 'orders/1/status', ['status' => 'confirmed']), 401, 'UNAUTHENTICATED');
    }

    public function test_a_guest_gets_401_not_403_on_the_auth_routes(): void
    {
        $this->assertApiError($this->api(null, 'GET', 'auth/me'), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->api(null, 'POST', 'auth/logout'), 401, 'UNAUTHENTICATED');
    }

    public function test_an_admin_cannot_create_an_order(): void
    {
        $admin = $this->makeUser(UserRole::Admin);

        $this->assertApiError(
            $this->api($admin, 'POST', 'orders', ['items' => [['product_id' => 1, 'quantity' => 1]]]),
            403,
            'FORBIDDEN',
        );
    }

    public function test_a_customer_cannot_change_an_order_status(): void
    {
        $customer = $this->makeUser();

        // The order has to exist: route model binding runs before the can: middleware,
        // so a missing id would be answered with 404 and never reach the role check.
        $order = Order::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'total' => '10.00',
        ]);

        $this->assertApiError(
            $this->api($customer, 'PATCH', "orders/{$order->id}/status", ['status' => 'cancelled']),
            403,
            'FORBIDDEN',
        );

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_an_unknown_route_is_404_in_the_unified_envelope(): void
    {
        $this->assertApiError($this->api(null, 'GET', 'does-not-exist'), 404, 'NOT_FOUND');
    }

    public function test_a_wrong_method_on_orders_is_405(): void
    {
        $this->assertApiError($this->api($this->makeUser(), 'DELETE', 'orders'), 405, 'METHOD_NOT_ALLOWED');
    }

    public function test_the_health_route_stays_public(): void
    {
        $this->api(null, 'GET', 'health')->assertOk()->assertJsonPath('data.status', 'ok');
    }
}
