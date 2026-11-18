<?php

namespace Modules\Orders\Broadcasting;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Modules\Orders\Models\Order;

/**
 * private-admin.orders: the live dashboard. Only the admin role may subscribe.
 *
 * It reuses the rule the API already uses for "an admin sees every order" (OrderPolicy::viewAll).
 */
class AdminOrdersChannel
{
    public function join(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('viewAll', Order::class);
    }
}
