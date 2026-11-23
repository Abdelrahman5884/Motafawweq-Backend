<?php

namespace App\Http\Middleware;

use App\Services\V1\Admin\PlatformSettingsService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces Platform Maintenance Mode configured by the Platform Admin.
 *
 * SECURITY & AVAILABILITY:
 * - Always allows health checks, auth login/logout/me, and authenticated admin sessions.
 * - Blocks all non-admin traffic with HTTP 503 + Retry-After when maintenance_mode is enabled.
 */
class CheckMaintenanceMode
{
    public function __construct(
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settingsService->isMaintenanceMode()) {
            return $next($request);
        }

        // Always allow health checks, admin login/me/refresh/logout, and public status
        if (
            $request->is('api/ping')
            || $request->is('api/v1/auth/login')
            || $request->is('api/v1/auth/logout')
            || $request->is('api/v1/auth/refresh')
            || $request->is('api/v1/auth/me')
            || $request->is('api/v1/public/status')
        ) {
            return $next($request);
        }

        // Allow authenticated Admin users seamlessly
        $user = $request->user() ?? $request->user('sanctum');
        if (! $user && ($bearer = $request->bearerToken())) {
            $token = PersonalAccessToken::findToken($bearer);
            $user = $token?->tokenable;
        }

        if ($user && method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => __('messages.admin.maintenance_mode_active'),
            'error_code' => 'MAINTENANCE_MODE',
        ], Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => '300',
        ]);
    }
}
