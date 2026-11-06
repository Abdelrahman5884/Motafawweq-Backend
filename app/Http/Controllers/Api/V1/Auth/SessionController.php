<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\V1\LoginHistoryResource;
use App\Http\Resources\V1\SessionResource;
use App\Repositories\Contracts\LoginHistoryRepositoryInterface;
use App\Services\V1\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends BaseApiController
{
    public function __construct(
        protected TokenService $tokenService,
        protected LoginHistoryRepositoryInterface $loginHistory
    ) {}

    /**
     * GET /auth/sessions — active devices.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = $this->tokenService->listSessions($request->user());

        return $this->successResponse(SessionResource::collection($sessions), 'تم جلب الأجهزة النشطة');
    }

    /**
     * DELETE /auth/sessions/{sessionId} — sign out a specific device.
     * Scoped to the authenticated user's own tokens (no IDOR possible).
     */
    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $deleted = $this->tokenService->revokeSession($request->user(), $sessionId);

        if ($deleted === 0) {
            return $this->notFoundResponse('الجلسة غير موجودة.');
        }

        $this->logSecurityEvent('SESSION_REVOKED', ['user_id' => $request->user()->id, 'session_id' => $sessionId]);

        return $this->successResponse(null, 'تم تسجيل الخروج من الجهاز بنجاح');
    }

    /**
     * GET /auth/login-history
     */
    public function history(Request $request): JsonResponse
    {
        $history = $this->loginHistory->forUser($request->user(), (int) $request->query('per_page', 20));

        return $this->paginatedResponse($history, LoginHistoryResource::class);
    }
}

