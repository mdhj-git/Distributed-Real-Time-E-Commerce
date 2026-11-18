<?php

use Illuminate\Support\Facades\Route;
use Modules\Web\Http\Controllers\PageController;

// Page routes of the web UI. No "web" middleware group is applied (see Providers/RouteServiceProvider.php), and
// no route passes data to its view: every page is a shell whose JavaScript calls /api/v1 with the JWT.
// Whether the person may use a page is decided by the API and by the broadcast channels, not by these routes.
// Static files (css, js, pusher-js) are served by nginx from public/ui.

Route::get('/', [PageController::class, 'home'])->name('web.home');

Route::get('register', [PageController::class, 'register'])->name('web.register');
Route::get('login', [PageController::class, 'login'])->name('web.login');
Route::get('account', [PageController::class, 'account'])->name('web.account');

Route::get('products', [PageController::class, 'products'])->name('web.products');
Route::get('products/{id}', [PageController::class, 'product'])->whereNumber('id')->name('web.product');

Route::get('orders', [PageController::class, 'orders'])->name('web.orders');
Route::get('orders/new', [PageController::class, 'ordersNew'])->name('web.orders.new');
Route::get('orders/{id}', [PageController::class, 'order'])->whereNumber('id')->name('web.order');

Route::get('notifications', [PageController::class, 'notifications'])->name('web.notifications');

Route::get('admin/orders', [PageController::class, 'adminOrders'])->name('web.admin.orders');
Route::get('admin/products/new', [PageController::class, 'adminProductNew'])->name('web.admin.products.new');
Route::get('admin/products/{id}/edit', [PageController::class, 'adminProductEdit'])
    ->whereNumber('id')
    ->name('web.admin.products.edit');
