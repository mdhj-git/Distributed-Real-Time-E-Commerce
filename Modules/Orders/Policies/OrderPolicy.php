<?php

namespace Modules\Orders\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Orders\Models\Order;
use Modules\Users\Contracts\UserRole;

/**
 * Ownership rules only. Who may create an order or change a status is enforced by the
 * can:customer and can:admin route middleware. The user is typed as Authenticatable so the
 * Users module's model is never imported; only UserRole from its Contracts is used.
 */
class OrderPolicy
{
    /** The admin sees every order; everyone else is limited to their own in the query itself. */
    public function viewAll(Authenticatable $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(Authenticatable $user, Order $order): bool
    {
        return $this->isAdmin($user) || $order->user_id === (int) $user->getAuthIdentifier();
    }

    private function isAdmin(Authenticatable $user): bool
    {
        return ($user->role ?? null) === UserRole::Admin;
    }
}
