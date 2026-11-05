<?php

namespace Modules\Notifications\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Notifications\Listeners\NotifyOrderPlaced;
use Modules\Notifications\Listeners\NotifyOrderStatusChanged;
use Modules\Orders\Contracts\Events\OrderPlaced;
use Modules\Orders\Contracts\Events\OrderStatusChanged;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Only events from Orders' Contracts are listened to, never anything else from that module.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        OrderPlaced::class => [NotifyOrderPlaced::class],
        OrderStatusChanged::class => [NotifyOrderStatusChanged::class],
    ];

    /**
     * Listeners are registered explicitly above, never by discovery. (Laravel only runs discovery for its own
     * base provider, so this is a safety setting: it guarantees a listener can never be registered twice.)
     */
    protected static $shouldDiscoverEvents = false;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
