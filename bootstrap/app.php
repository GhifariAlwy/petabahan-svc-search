<?php

use App\Domain\Auth\Middleware\EnsureRole;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use App\Support\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => \App\Domain\Auth\Middleware\AuthenticateGatewayOrSanctum::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ApiException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                code: $e->errorCode->value,
                message: $e->getMessage(),
                status: $e->httpStatus,
                details: $e->details,
            );
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                code: ErrorCode::VALIDATION_ERROR->value,
                message: $e->getMessage() ?: 'Data yang dikirim tidak valid.',
                status: 422,
                details: ['fields' => $e->errors()],
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                code: ErrorCode::UNAUTHENTICATED->value,
                message: 'Autentikasi diperlukan.',
                status: 401,
            );
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                code: ErrorCode::FORBIDDEN->value,
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Anda tidak memiliki akses ke sumber daya ini.',
                status: 403,
            );
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                code: ErrorCode::NOT_FOUND->value,
                message: 'Data tidak ditemukan.',
                status: 404,
            );
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                code: ErrorCode::TOO_MANY_REQUESTS->value,
                message: 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
                status: 429,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = $e->getStatusCode();
            $code = match ($status) {
                401 => ErrorCode::UNAUTHENTICATED,
                403 => ErrorCode::FORBIDDEN,
                404 => ErrorCode::NOT_FOUND,
                422 => ErrorCode::VALIDATION_ERROR,
                429 => ErrorCode::TOO_MANY_REQUESTS,
                default => ErrorCode::INTERNAL_ERROR,
            };

            return ApiResponse::error(
                code: $code->value,
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Permintaan tidak dapat diproses.',
                status: $status,
            );
        });
    })->create();
