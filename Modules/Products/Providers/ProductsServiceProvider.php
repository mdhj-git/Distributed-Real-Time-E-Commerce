<?php

namespace Modules\Products\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Products\Console\ImportStockCommand;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Contracts\ProductCatalog;
use Modules\Products\Models\Product;
use Modules\Products\Policies\ProductPolicy;
use Modules\Products\Services\LocalInventoryService;
use Modules\Products\Services\ProductCatalogService;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ProductsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Products';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'products';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Bind the public contracts of this module. Other modules depend on the
     * interfaces only, so this is the single place the implementation is chosen.
     */
    public function register(): void
    {
        parent::register();

        $this->app->bind(ProductCatalog::class, ProductCatalogService::class);
        $this->app->bind(InventoryService::class, LocalInventoryService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Product::class, ProductPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ImportStockCommand::class]);
        }
    }
}
