<?php

namespace Modules\Products\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Products\Models\Product;
use Modules\Users\Contracts\UserRole;

class ProductPolicy
{
    use HandlesAuthorization;

    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Product $product): bool
    {
        return true;
    }

    public function create(Authenticatable $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(Authenticatable $user, Product $product): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(Authenticatable $user, Product $product): bool
    {
        return $this->isAdmin($user);
    }

    public function uploadImage(Authenticatable $user, Product $product): bool
    {
        return $this->isAdmin($user);
    }

    public function adjustStock(Authenticatable $user, Product $product): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(Authenticatable $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
