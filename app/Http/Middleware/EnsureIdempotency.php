<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevents duplicate execution of sensitive student mutations (exam submit, payment, group join)
 * when the client supplies an `Idempotency-Key` header.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $rawKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($rawKey === '') {
            return $next($request);
        }

        if (strlen($rawKey) > 128 || ! preg_match('/^[A-Za-z0-9\-_:.]+$/', $rawKey)) {
            return response()->json([
                'success' => false,
                'message' => 'مفتاح Idempotency-Key غير صالح.',
                'error_code' => 'INVALID_IDEMPOTENCY_KEY',
            ], 422);
        }

        $userId = $request->user()?->id ?? $request->ip();
        $cacheKey = sprintf('idemp:%s:%s:%s:%s', $userId, $request->method(), sha1($request->path()), $rawKey);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response($cached['body'], $cached['status'])
                ->withHeaders(array_merge($cached['headers'] ?? [], ['X-Idempotent-Replayed' => 'true']));
        }

        $lock = Cache::lock($cacheKey.':lock', 15);
        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'يتم معالجة نفس الطلب حالياً، يرجى الانتظار.',
                'error_code' => 'IDEMPOTENCY_CONFLICT',
            ], 409);
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'headers' => [
                        'Content-Type' => $response->headers->get('Content-Type', 'application/json'),
                    ],
                ], now()->addHours(24));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
