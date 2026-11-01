<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Exceptions\AppException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // Global (antes del ruteo) para que también los 404/405 de /api respondan JSON.
        $middleware->prepend(\App\Http\Middleware\EnsureJsonResponse::class);

        $middleware->api(prepend: [\App\Http\Middleware\ApplyWorkshopSettings::class]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 401,
                    'message' => 'No autenticado.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'UNAUTHENTICATED',
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 422,
                    'message' => 'Los datos proporcionados no son válidos.',
                    'data' => null,
                    'errors' => $e->errors(),
                    'error_code' => 'VALIDATION_ERROR',
                ], 422);
            }
        });
        
        $exceptions->render(function (AppException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => $e->getStatus(),
                    'message' => $e->getMessage(),
                    'data' => null,
                    'errors' => $e->getErrors(),
                    'error_code' => $e->getErrorCode(),
                ], $e->getStatus());
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 404,
                    'message' => 'Recurso no encontrado.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'RESOURCE_NOT_FOUND',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 404,
                    'message' => 'Recurso no encontrado.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'RESOURCE_NOT_FOUND',
                ], 404);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 405,
                    'message' => 'Método HTTP no permitido.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'METHOD_NOT_ALLOWED',
                ], 405);
            }
        });

        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 403,
                    'message' => 'No tienes permisos para realizar esta acción.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'FORBIDDEN',
                ], 403);
            }
        });

        $exceptions->render(function (BadRequestHttpException $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 400,
                    'message' => 'Solicitud incorrecta.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'BAD_REQUEST',
                ], 400);
            }
        });

        $exceptions->render(function (UnsupportedMediaTypeHttpException $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 415,
                    'message' => 'Tipo de contenido no soportado.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'UNSUPPORTED_MEDIA_TYPE',
                ], 415);
            }
        });

        $exceptions->render(function (NotAcceptableHttpException $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 406,
                    'message' => 'Formato de respuesta no aceptable.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'NOT_ACCEPTABLE',
                ], 406);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 429,
                    'message' => 'Demasiadas solicitudes.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'TOO_MANY_REQUESTS',
                ], 429);
            }
        });

        $exceptions->render(function (ServiceUnavailableHttpException $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 503,
                    'message' => 'Servicio no disponible.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'SERVICE_UNAVAILABLE',
                ], 503);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) 
        {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => 500,
                    'message' => 'Error interno del servidor.',
                    'data' => null,
                    'errors' => null,
                    'error_code' => 'INTERNAL_SERVER_ERROR',
                ], 500);
            }
        });

    })->create();
