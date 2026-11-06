<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    use ApiResponse;

    /**
     * Handle an incoming request and verify user role.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthorizedResponse();
        }

        $userRole = is_object($user->role) ? $user->role->value : (string) $user->role;

        // Flatten any comma-separated roles passed like "admin,teacher"
        $allowedRoles = [];
        foreach ($roles as $r) {
            $allowedRoles = array_merge($allowedRoles, explode(',', $r));
        }

        if (!in_array($userRole, $allowedRoles, true)) {
            return $this->forbiddenResponse(__('messages.forbidden'));
        }

        return $next($request);
    }
}

