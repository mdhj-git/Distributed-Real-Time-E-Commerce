<?php

namespace Modules\Web\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Web';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapWebRoutes();
    }

    /**
     * Define the page routes.
     *
     * Deliberately NOT in the "web" middleware group: no session, no cookies, no CSRF. The pages are plain HTML
     * shells; they fetch every piece of data from /api/v1 with the JWT, so the application stays stateless.
     */
    protected function mapWebRoutes(): void
    {
        Route::group([], module_path($this->name, '/routes/web.php'));
    }
}
