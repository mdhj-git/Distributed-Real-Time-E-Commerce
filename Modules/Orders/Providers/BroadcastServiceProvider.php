<?php

namespace Modules\Orders\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Modules\Orders\Broadcasting\AdminOrdersChannel;
use Modules\Orders\Broadcasting\OrderChannel;

/**
 * Real-time (Requirement 4): the channel authorization route and the two private channels.
 *
 * bootstrap/app.php is frozen, so this provider registers them instead (install:broadcasting is not used).
 * The route is POST /api/v1/broadcasting/auth. It sits in the "api" group, so it has the same request id, error
 * format and rate limiter as every API route, and "auth:api" answers 401 before any channel is looked at.
 * Both channels name the "api" guard, so the identity is always the JWT of the Bearer token.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Broadcast::routes([
            'prefix' => 'api/v1',
            'middleware' => ['api', 'auth:api'],
        ]);

        // Wire names are "private-orders.{id}" and "private-admin.orders".
        Broadcast::channel('orders.{order}', OrderChannel::class, ['guards' => ['api']]);
        Broadcast::channel('admin.orders', AdminOrdersChannel::class, ['guards' => ['api']]);
    }
}
