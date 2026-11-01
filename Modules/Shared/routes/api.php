<?php

use Illuminate\Support\Facades\Route;
use Modules\Shared\Http\Controllers\HealthController;

// The api/v1 prefix and the api middleware group are applied in Providers/RouteServiceProvider.php.
Route::get('health', HealthController::class)->withoutMiddleware('throttle:api')->name('health');
