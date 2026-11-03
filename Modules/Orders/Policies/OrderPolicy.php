<?php

namespace Modules\Orders\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Orders\Models\Order;

/**
 * The user is typed as Authenticatable so the Users module's model is never imported.
 * Anything not allowed here is denied.
 */
class OrderPolicy
{
    private const ROLE_ADMIN = 'admin';

    private const ROLE_CUSTOMER = 'customer';

    /** Any authenticated user may list orders; what the list contains is decided by viewAll(). */
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    /** The admin sees every order; everyone else is limited to their own in the query itself. */
    public function viewAll(Authenticatable $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(Authenticatable $user, Order $order): bool
    {
        return $this->isAdmin($user) || $order->user_id === (int) $user->getAuthIdentifier();
    }

    /** Only customers place orders. */
    public function create(Authenticatable $user): bool
    {
        return $this->roleOf($user) === self::ROLE_CUSTOMER;
    }

    /** Only the admin changes a status; a customer cannot even cancel their own order. */
    public function updateStatus(Authenticatable $user, Order $order): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(Authenticatable $user): bool
    {
        return $this->roleOf($user) === self::ROLE_ADMIN;
    }

    /**
     * Works whether the Users module casts role to a backed enum or leaves it a plain string.
     */
    private function roleOf(Authenticatable $user): ?string
    {
        $role = $user->role ?? null;

        if ($role instanceof \BackedEnum) {
            return (string) $role->value;
        }

        return is_string($role) ? $role : null;
    }
}
