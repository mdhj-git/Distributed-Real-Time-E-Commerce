<?php

namespace Modules\Users\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Users\Contracts\UserRole;
use Modules\Users\Models\User;
use Nwidart\Modules\Support\ModuleServiceProvider;

class UsersServiceProvider extends ModuleServiceProvider
{
    protected string $name      = 'Users';
    protected string $nameLower = 'users';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();
        $this->configureRateLimiting();
        $this->configureGates();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(
                (is_string($email) ? mb_strtolower(trim($email)) : '') . '|' . $request->ip()
            );
        });
    }

    protected function configureGates(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->role === UserRole::Admin);
        Gate::define('customer', fn (User $user): bool => $user->role === UserRole::Customer);
    }
}