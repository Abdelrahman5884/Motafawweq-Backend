<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Handle an incoming request and sanitize string inputs.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();

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

