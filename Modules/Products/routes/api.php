<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\CategoryController;
use Modules\Products\Http\Controllers\ProductController;
use Modules\Products\Http\Middleware\ConditionalEtag;

// The api/v1 prefix and the api middleware group are applied in Providers/RouteServiceProvider.php.

// "reads.primary" (a pass-through alias, see App\Http\Middleware\ReadsOnPrimary): the three catalog reads use the
// primary database while CATALOG_READS_ON_PRIMARY is true, so a product that was just created or deleted is never
// a 404 or a ghost during the replica lag. The Redis cache is unchanged.
Route::get('categories', [CategoryController::class, 'index'])
    ->middleware('reads.primary')
    ->name('categories.index');

Route::get('products', [ProductController::class, 'index'])
    ->middleware('reads.primary')
    ->name('products.index');
Route::get('products/{product}', [ProductController::class, 'show'])
    ->middleware([ConditionalEtag::class, 'reads.primary'])
    ->name('products.show');

Route::middleware(['auth:api', 'can:admin'])->group(function () {
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    Route::post('products/{product}/images', [ProductController::class, 'storeImages'])->name('products.images.store');

    Route::patch('products/{product}/stock', [ProductController::class, 'adjustStock'])->name('products.stock.adjust');
});
