<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Shared\Exceptions\ExceptionRenderer;
use Modules\Shared\Http\Middleware\ForceJsonResponse;
use Modules\Shared\Http\Middleware\RequestId;
use Modules\Shared\Http\Middleware\SecurityHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([
            RequestId::class,
            ForceJsonResponse::class,
            SecurityHeaders::class,
        ]);

        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ExceptionRenderer::register($exceptions);
    })->create();
