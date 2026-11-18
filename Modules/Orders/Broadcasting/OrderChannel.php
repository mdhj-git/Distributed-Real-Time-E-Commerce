<?php

namespace Modules\Orders\Broadcasting;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Modules\Orders\Models\Order;

/**
 * private-orders.{order}: only the customer who owns the order may follow it.
 *
 * An id that does not exist is refused too (403), because the "Order $order" binding fails. The ownership rule
 * itself is OrderPolicy::follow.
 */
class OrderChannel
{
    public function join(Authenticatable $user, Order $order): bool
    {
        return Gate::forUser($user)->allows('follow', $order);
    }
}
