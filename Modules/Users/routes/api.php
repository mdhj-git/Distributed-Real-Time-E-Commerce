<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\AuthController;

// The api/v1 prefix and the api middleware group are applied in Providers/RouteServiceProvider.php.

Route::prefix('auth')->group(function () {

    // مسارات عامة
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login',    [AuthController::class, 'login'])->middleware('throttle:login');

    // refresh خارج auth:api — يقبل الرمز المنتهي ضمن مدة التجديد
    Route::post('refresh',  [AuthController::class, 'refresh']);

    // مسارات محمية
    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',      [AuthController::class, 'me']);
    });
});