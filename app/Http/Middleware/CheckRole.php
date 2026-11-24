<?php

namespace App\Http\Middleware;

use App\Models\SecurityAuditLog;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CheckRole
{
    use ApiResponse;

    /**
     * Handle an incoming request and verify user role.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->unauthorizedResponse();
        }

        $userRole = is_object($user->role) ? $user->role->value : (string) $user->role;

        // Flatten any comma-separated roles passed like "admin,teacher"
        $allowedRoles = [];
        foreach ($roles as $r) {
            $allowedRoles = array_merge($allowedRoles, explode(',', $r));
        }

        if (! in_array($userRole, $allowedRoles, true)) {
            if (in_array('admin', $allowedRoles, true)) {
                try {
                    SecurityAuditLog::query()->create([
                        'uuid' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'event' => 'security.unauthorized_admin_access',
                        'level' => 'warning',
                        'ip_address' => $request->ip(),
                        'user_agent' => Str::limit((string) $request->userAgent(), 255),
                        'context' => [
                            'actual_role' => $userRole,
                            'path' => $request->path(),
                            'method' => $request->method(),
                        ],
                        'created_at' => now(),
                    ]);
                } catch (Throwable) {
                    // Never break error handling if audit logging fails
                }
            }

            return $this->forbiddenResponse(__('messages.forbidden'));
        }

        return $next($request);
    }
}
