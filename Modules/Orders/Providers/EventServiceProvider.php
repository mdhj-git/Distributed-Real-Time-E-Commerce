<?php

namespace Modules\Orders\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Orders\Contracts\Events\OrderPlaced;
use Modules\Orders\Contracts\Events\OrderStatusChanged;
use Modules\Orders\Listeners\BroadcastOrderPlaced;
use Modules\Orders\Listeners\BroadcastOrderStatusChanged;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * Orders reacts to its own events here to push them to the browser (Requirement 4, real time).
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        OrderPlaced::class => [BroadcastOrderPlaced::class],
        OrderStatusChanged::class => [BroadcastOrderStatusChanged::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
