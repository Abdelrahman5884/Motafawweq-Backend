<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale from:
 *   1. ?lang=ar|en query parameter
 *   2. X-Locale header
 *   3. Accept-Language header
 *   4. Default app.locale ('ar')
 */
class SetLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->query('lang')
            ?: $request->header('X-Locale')
            ?: $request->header('Accept-Language')
            ?: config('app.locale', 'ar');

        // Parse primary language tag (e.g. "en-US,en;q=0.9" -> "en")
        $primary = strtolower(substr(trim(explode(',', (string) $raw)[0]), 0, 2));
        $locale = in_array($primary, self::SUPPORTED, true) ? $primary : 'ar';

        App::setLocale($locale);

        $response = $next($request);
        if (method_exists($response, 'headers')) {
            $response->headers->set('Content-Language', $locale);
        }

        return $response;
    }
}
