<?php

namespace Modules\Notifications\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Policies\UserNotificationPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Notifications';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'notifications';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(UserNotification::class, UserNotificationPolicy::class);
    }
}
