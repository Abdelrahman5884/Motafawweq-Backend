<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enforce JSON & Security Headers on all API requests
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            SecurityHeaders::class,
            SanitizeInput::class,
        ]);

        // Register custom middleware aliases
        $middleware->alias([
            'role'           => CheckRole::class,
            'abilities'      => CheckAbilities::class,
            'ability'        => CheckForAnyAbility::class,
            'email.verified' => EnsureEmailIsVerified::class,
            'account.active' => EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        // Uniform API Exception Handling
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'بيانات الإدخال غير صالحة، يرجى مراجعة الأخطاء',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'success'    => false,
                    'message'    => 'غير مصرح لك، يرجى تسجيل الدخول أولاً للوصول إلى هذا المورد.',
                    'error_code' => 'UNAUTHENTICATED',
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                if ($e instanceof MissingAbilityException || $e->getPrevious() instanceof MissingAbilityException) {
                    return response()->json([
                        'success'    => false,
                        'message'    => 'نوع رمز الدخول غير صالح لهذا الطلب.',
                        'error_code' => 'INVALID_TOKEN_TYPE',
                    ], 401);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'ليس لديك الصلاحيات الكافية للقيام بهذا الإجراء.',
                ], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'المورد المطلوب غير موجود.',
                ], 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException|TooManyRequestsHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                $message = $e->getMessage() && $e->getMessage() !== 'Too Many Attempts.'
                    ? $e->getMessage()
                    : 'تم تجاوز الحد المسموح به من الطلبات. يرجى الانتظار قليلاً ثم المحاولة مجدداً.';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 429, $e->getHeaders());
            }
        });

        // Safe Fallback for unhandled server errors (never leak stack trace in production)
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (!$isApi($request)) {
                return null;
            }

            // Pre-built responses (e.g. FormRequest failures) pass through untouched
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            // Known HTTP errors (405, 413, 419...) keep their real status code
            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'تعذر معالجة الطلب.',
                ], $e->getStatusCode(), $e->getHeaders());
            }

            Log::error('[API-UNHANDLED-EXCEPTION] ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return response()->json(array_filter([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'حدث خطأ غير متوقع في الخادم. يرجى المحاولة لاحقاً.',
                'debug'   => config('app.debug') ? [
                    'exception' => get_class($e),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ] : null,
            ], fn ($v) => $v !== null), 500);
        });
    })->create();
