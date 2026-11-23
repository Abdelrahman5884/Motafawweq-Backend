<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\StoreCenterStaffRequest;
use App\Http\Requests\V1\Center\UpdateCenterStaffRequest;
use App\Models\SecurityAuditLog;
use App\Services\V1\Center\CenterStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CenterStaffController extends BaseApiController
{
    public function __construct(
        protected CenterStaffService $staffService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffService->listStaff(
            $this->authenticatedUser(),
            $request->only(['branch_id', 'role', 'search'])
        );

        return $this->successResponse(
            $staff,
            __('messages.center.staff_fetched'),
            meta: ['total' => count($staff)]
        );
    }

    public function store(StoreCenterStaffRequest $request): JsonResponse
    {
        $member = $this->staffService->createStaff(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse($member, __('messages.center.staff_created'));
    }

    public function update(UpdateCenterStaffRequest $request, string $uuid): JsonResponse
    {
        $member = $this->staffService->updateStaff(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse($member, __('messages.center.staff_updated'));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->staffService->deleteStaff($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.center.staff_deleted'));
    }

    /**
     * GET /api/v1/center/audit-logs
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $paginator = $this->staffService->listAuditLogs(
            $this->authenticatedUser(),
            $request->only(['level', 'event', 'search']),
            (int) $request->query('per_page', 15)
        );

        $items = collect($paginator->items())->map(fn (SecurityAuditLog $log) => [
            'id' => $log->uuid,
            'event' => $log->event,
            'level' => $log->level,
            'auditableType' => $log->auditable_type ? class_basename($log->auditable_type) : null,
            'auditableId' => $log->auditable_id,
            'ipAddress' => $log->ip_address,
            'context' => $log->context ?? [],
            'createdAt' => $log->created_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'success' => true,
            'message' => __('messages.center.audit_logs_fetched'),
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }
}
