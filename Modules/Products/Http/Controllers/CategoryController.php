<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Products\Services\CachedProductReader;
use Modules\Shared\Helpers\ApiResponse;

class CategoryController extends Controller
{
    // GET /api/v1/categories  (cached read: no API route writes categories)
    public function index(CachedProductReader $reader): JsonResponse
    {
        $read = $reader->categories();

        return ApiResponse::ok($read['value'])->header('X-Cache', $read['hit'] ? 'HIT' : 'MISS');
    }
}
