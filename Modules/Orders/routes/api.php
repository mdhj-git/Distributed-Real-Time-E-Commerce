<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderController;

// The api/v1 prefix and the api middleware group are applied in Providers/RouteServiceProvider.php.
// auth:api always comes before can: so a guest gets 401, not 403.
// can:customer / can:admin are the role gates defined by the Users module.
Route::get('orders', [OrderController::class, 'index'])
    ->middleware(['auth:api'])
    ->name('orders.index');

Route::post('orders', [OrderController::class, 'store'])
    ->middleware(['auth:api', 'can:customer'])
    ->name('orders.store');

Route::get('orders/{order}', [OrderController::class, 'show'])
    ->middleware(['auth:api'])
    ->whereNumber('order')
    ->name('orders.show');

Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
    ->middleware(['auth:api', 'can:admin'])
    ->whereNumber('order')
    ->name('orders.status');
