<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\CategoryController;
use Modules\Products\Http\Controllers\ProductController;

// The api/v1 prefix and the api middleware group are applied in Providers/RouteServiceProvider.php.

Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');

Route::get('products', [ProductController::class, 'index'])->name('products.index');
Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');

Route::middleware(['auth:api', 'can:admin'])->group(function () {
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    Route::post('products/{product}/images', [ProductController::class, 'storeImages'])->name('products.images.store');

    Route::patch('products/{product}/stock', [ProductController::class, 'adjustStock'])->name('products.stock.adjust');
});
