<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Products\Http\Resources\CategoryResource;
use Modules\Products\Models\Category;
use Modules\Shared\Helpers\ApiResponse;

class CategoryController extends Controller
{
    // GET /api/v1/categories
    public function index(): JsonResponse
    {
        return ApiResponse::ok(
            CategoryResource::collection(Category::query()->orderBy('name')->get()),
        );
    }
}
