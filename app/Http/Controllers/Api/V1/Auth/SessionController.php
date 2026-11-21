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

    public function index(Request $request): JsonResponse
    {
        $sessions = $this->tokenService->listSessions($request->user());

        return $this->successResponse(SessionResource::collection($sessions), __('messages.sessions_fetched'));
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $deleted = $this->tokenService->revokeSession($request->user(), $sessionId);

        if ($deleted === 0) {
            return $this->notFoundResponse(__('messages.session_not_found'));
        }

        $this->logSecurityEvent('SESSION_REVOKED', ['user_id' => $request->user()->id, 'session_id' => $sessionId]);

        return $this->successResponse(null, __('messages.session_revoked'));
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentSession = $user->currentAccessToken()?->session_id;
        $deletedTokens = $this->tokenService->revokeAllExcept($user, $currentSession);

        if ($deletedTokens > 0) {
            $this->logSecurityEvent('OTHER_SESSIONS_REVOKED', [
                'user_id' => $user->id,
                'revoked_tokens' => $deletedTokens,
            ]);
        }

        return $this->successResponse(
            ['revoked_tokens' => $deletedTokens],
            $deletedTokens > 0 ? __('messages.other_sessions_revoked') : __('messages.no_other_sessions')
        );
    }

    public function history(Request $request): JsonResponse
    {
        $history = $this->loginHistory->forUser($request->user(), (int) $request->query('per_page', 20));

        return $this->paginatedResponse($history, LoginHistoryResource::class);
    }
}
