<?php

namespace Modules\Orders\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Orders\Contracts\Events\OrderPlaced;
use Modules\Orders\Contracts\Events\OrderStatusChanged;
use Modules\Orders\Listeners\BroadcastOrderPlaced;
use Modules\Orders\Listeners\BroadcastOrderStatusChanged;

class EventServiceProvider extends ServiceProvider
{

    protected $listen = [
        OrderPlaced::class => [BroadcastOrderPlaced::class],
        OrderStatusChanged::class => [BroadcastOrderStatusChanged::class],
    ];

    protected static $shouldDiscoverEvents = true;

    protected function configureEmailVerification(): void {}
}
