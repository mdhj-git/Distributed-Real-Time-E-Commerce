<?php

namespace App\Providers;

use App\Http\Middleware\ReadRouting;
use App\Http\Middleware\ReadsOnPrimary;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Documents the JWT bearer scheme so "Try it" in /docs/api can send the token.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer', 'JWT'));
        });

        $this->configureReadReplica();
    }

    /**
     * Read replica wiring (Requirement 3, Step 6). bootstrap/app.php is frozen, so it is done here.
     */
    private function configureReadReplica(): void
    {
        $router = $this->app['router'];

        // Alias for the pass-through that marks catalog routes (Modules/Products/routes/api.php).
        $router->aliasMiddleware(ReadRouting::CATALOG_ALIAS, ReadsOnPrimary::class);

        // First in the "api" group, so it runs before the guard's user lookup and route-model binding.
        // pushMiddlewareToGroup would append it after SubstituteBindings.
        $router->prependMiddlewareToGroup('api', ReadRouting::class);

        // Console processes always read from the primary: migrate (its "does the migrations table exist" check
        // uses the read connection), db:seed, perf:seed, orders:race-test and every other artisan command must
        // never depend on a replica that is stopped or behind. Done at run time, not in config/database.php, so a
        // later config:cache cannot freeze it into the web config. Only when reads are split: with a single host
        // nothing is touched and no connection object is created at boot.
        $default = config('database.default');

        if ($this->app->runningInConsole() && config("database.connections.{$default}.read")) {
            DB::connection()->useWriteConnectionWhenReading();
        }
    }
}
