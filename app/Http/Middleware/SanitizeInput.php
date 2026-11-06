<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Secret fields must reach the hasher byte-for-byte and are never rendered,
     * so they are excluded from sanitization.
     *
     * @var list<string>
     */
    public const EXCEPT = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'refresh_token',
    ];

    /**
     * Handle an incoming request and sanitize string inputs.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->except(self::EXCEPT);

        if (!empty($input)) {
            array_walk_recursive($input, function (&$value) {
                if (is_string($value)) {
                    // Strip null byte attacks
                    $value = str_replace(chr(0), '', $value);
                    // Strip script and html tags
                    $value = strip_tags($value);
                    // Trim excess whitespace
                    $value = trim($value);
                }
            });

            $request->merge($input);
        }

        return $next($request);
    }
}
