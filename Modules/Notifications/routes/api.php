<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\NotificationController;

// The api/v1 prefix and the api middleware group are applied in Providers/RouteServiceProvider.php.
// auth:api always comes before any can: so a guest gets 401, not 403.
// Any signed-in user (admin or customer) has their own notifications; ownership is checked by the Policy.
Route::middleware('auth:api')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.read-all');

    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->whereNumber('notification')
        ->name('notifications.read');
});
