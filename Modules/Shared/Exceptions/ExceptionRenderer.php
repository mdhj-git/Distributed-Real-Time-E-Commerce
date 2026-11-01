<?php

namespace Modules\Shared\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\SecretMissingException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ExceptionRenderer
{
    /**
     * Fixed code and message per status. Framework and package messages are never exposed,
     * because a model binding 404 carries the full class name of the model.
     */
    private const HTTP_ERRORS = [
        401 => ['UNAUTHENTICATED', 'Unauthenticated.'],
        403 => ['FORBIDDEN', 'This action is forbidden.'],
        404 => ['NOT_FOUND', 'The requested resource was not found.'],
        405 => ['METHOD_NOT_ALLOWED', 'The request method is not allowed.'],
        413 => ['PAYLOAD_TOO_LARGE', 'The request payload is too large.'],
        429 => ['TOO_MANY_ATTEMPTS', 'Too many attempts.'],
    ];

    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // dontReport() matches subclasses, so it cannot keep SecretMissingException reported.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof ApiException
            || ($e instanceof JWTException && ! $e instanceof SecretMissingException));

        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? self::toResponse($e) : null;
        });
    }

    /**
     * Returning null lets the framework send the response an HttpResponseException carries.
     */
    private static function toResponse(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof HttpResponseException => null,
            $e instanceof ApiException => self::error($e->status, $e->errorCode, $e->getMessage(), $e->details),
            $e instanceof ValidationException => self::error($e->status, 'VALIDATION_FAILED', $e->getMessage(), $e->errors()),
            $e instanceof AuthenticationException => self::error(401, ...self::HTTP_ERRORS[401]),
            $e instanceof SecretMissingException => self::serverError(),
            $e instanceof JWTException => self::error(401, ...self::HTTP_ERRORS[401]),
            $e instanceof HttpExceptionInterface => self::httpError($e),
            default => self::serverError(),
        };
    }

    private static function httpError(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        [$code, $message] = self::HTTP_ERRORS[$status]
            ?? ['HTTP_ERROR', Response::$statusTexts[$status] ?? 'Unknown Status'];

        return self::error($status, $code, $message, [], $e->getHeaders());
    }

    private static function serverError(): JsonResponse
    {
        return self::error(500, 'SERVER_ERROR', 'An unexpected error occurred.');
    }

    private static function error(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => Context::get('request_id'),
            ],
        ], $status, $headers);
    }
}
