<?php

namespace Modules\Shared\Helpers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ApiResponse
{
    public static function ok(mixed $data): JsonResponse
    {
        return new JsonResponse(['data' => $data]);
    }

    public static function created(mixed $data, ?string $location = null): JsonResponse
    {
        return new JsonResponse(['data' => $data], 201, $location === null ? [] : ['Location' => $location]);
    }

    public static function noContent(): Response
    {
        return new Response(status: 204);
    }

    /**
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resource
     */
    public static function paginated(LengthAwarePaginator $paginator, ?string $resource = null): JsonResponse
    {
        return new JsonResponse([
            'data' => $resource === null ? $paginator->items() : $resource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
