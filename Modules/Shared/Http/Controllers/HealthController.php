<?php

namespace Modules\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Shared\Helpers\ApiResponse;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::ok(['status' => 'ok']);
    }
}
