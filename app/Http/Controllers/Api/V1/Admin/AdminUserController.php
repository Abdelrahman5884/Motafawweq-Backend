<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Admin\StoreAdminUserRequest;
use App\Http\Requests\V1\Admin\UpdateAdminUserRequest;
use App\Services\V1\Admin\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminUserController extends BaseApiController
{
    public function __construct(
        protected AdminUserService $userService
    ) {}

    /**
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->userService->listUsers([
            'search' => $request->query('search'),
            'role' => $request->query('role'),
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.users_fetched'),
            200,
            array_merge($result['meta'], ['summary' => $result['summary']])
        );
    }

    /**
     * GET /api/v1/admin/users/verifications
     */
    public function verifications(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $items = $this->userService->listVerificationQueue($status ? (string) $status : null);

        return $this->successResponse($items, __('messages.admin.verifications_fetched'));
    }

    /**
     * GET /api/v1/admin/users/export
     */
    public function exportCsv(Request $request): Response
    {
        $csv = $this->userService->exportUsersCsv($this->authenticatedUser(), [
            'search' => $request->query('search'),
            'role' => $request->query('role'),
            'status' => $request->query('status'),
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="motafawweq-users-'.now()->format('Y-m-d').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * POST /api/v1/admin/users
     */
    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser($this->authenticatedUser(), $request->validated());

        return $this->createdResponse($user, __('messages.admin.user_created'));
    }

    /**
     * GET /api/v1/admin/users/{id}
     */
    public function show(int $id): JsonResponse
    {
        $user = $this->userService->getUserDetails($this->authenticatedUser(), $id);

        return $this->successResponse($user, __('messages.admin.user_fetched'));
    }

    /**
     * PUT /api/v1/admin/users/{id}
     */
    public function update(UpdateAdminUserRequest $request, int $id): JsonResponse
    {
        $user = $this->userService->updateUser($this->authenticatedUser(), $id, $request->validated());

        return $this->successResponse($user, __('messages.admin.user_updated'));
    }

    /**
     * PATCH /api/v1/admin/users/{id}/toggle-status
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $user = $this->userService->toggleStatus($this->authenticatedUser(), $id);

        return $this->successResponse($user, __('messages.admin.user_status_toggled'));
    }

    /**
     * POST /api/v1/admin/users/{id}/verify
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->userService->approveVerification(
            $this->authenticatedUser(),
            $id,
            $validated['notes'] ?? null
        );

        return $this->successResponse($user, __('messages.admin.user_verified'));
    }

    /**
     * POST /api/v1/admin/users/{id}/reject-verification
     */
    public function rejectVerification(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->userService->rejectVerification(
            $this->authenticatedUser(),
            $id,
            $validated['reason'] ?? null
        );

        return $this->successResponse($user, __('messages.admin.user_verification_rejected'));
    }

    /**
     * POST /api/v1/admin/users/{id}/reset-password
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $result = $this->userService->triggerPasswordReset(
            $this->authenticatedUser(),
            $id,
            $request->ip()
        );

        return $this->successResponse($result, __('messages.admin.user_password_reset_sent'));
    }

    /**
     * POST /api/v1/admin/users/{id}/revoke-sessions
     */
    public function revokeSessions(int $id): JsonResponse
    {
        $result = $this->userService->revokeAllSessions($this->authenticatedUser(), $id);

        return $this->successResponse($result, __('messages.admin.user_sessions_revoked'));
    }

    /**
     * DELETE /api/v1/admin/users/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $this->userService->deleteUser($this->authenticatedUser(), $id);

        return $this->successResponse(null, __('messages.user_deleted'));
    }
}
