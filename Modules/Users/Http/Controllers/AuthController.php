<?php

namespace Modules\Users\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Shared\Helpers\ApiResponse;
use Modules\Users\Http\Requests\LoginRequest;
use Modules\Users\Http\Requests\RegisterRequest;
use Modules\Users\Http\Resources\UserResource;
use Modules\Users\Services\AuthService;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    // POST /api/v1/auth/register
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());
        $token = auth('api')->login($user);

        return ApiResponse::created([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    // POST /api/v1/auth/login
    public function login(LoginRequest $request): JsonResponse
    {
        $token = $this->authService->login($request->validated());

        if ($token === false) {
            // يُعالَج كـ 401 بصيغة الأخطاء الموحدة في معالج Laravel المركزي.
            throw new AuthenticationException();
        }

        return ApiResponse::ok([
            'user' => new UserResource(auth('api')->user()),
            'token' => $token,
        ]);
    }

    // POST /api/v1/auth/logout
    public function logout(): Response
    {
        $this->authService->logout();

        return ApiResponse::noContent();
    }

    // POST /api/v1/auth/refresh
    public function refresh(): JsonResponse
    {
        $token = $this->authService->refresh();

        return ApiResponse::ok([
            'token' => $token,
        ]);
    }

    // GET /api/v1/auth/me
    public function me(): JsonResponse
    {
        return ApiResponse::ok(
            new UserResource(auth('api')->user())
        );
    }
}
