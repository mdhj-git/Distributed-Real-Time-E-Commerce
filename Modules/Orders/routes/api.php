<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderController;

// The api/v1 prefix and the api middleware group are applied in Providers/RouteServiceProvider.php.
Route::middleware('auth:api')->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])
        ->whereNumber('order')
        ->name('orders.show');
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
        ->whereNumber('order')
        ->name('orders.status');
});
