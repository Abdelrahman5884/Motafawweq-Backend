<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Admin\UpdateAdminSystemSettingsRequest;
use App\Services\V1\Admin\AdminSystemService;
use App\Services\V1\Admin\PlatformSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemController extends BaseApiController
{
    public function __construct(
        protected AdminSystemService $systemService,
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * GET /api/v1/admin/system/overview
     */
    public function overview(): JsonResponse
    {
        return $this->successResponse(
            $this->systemService->getSystemOverview(),
            __('messages.admin.system_overview_fetched')
        );
    }

    /**
     * PUT /api/v1/admin/system/settings
     */
    public function updateSettings(UpdateAdminSystemSettingsRequest $request): JsonResponse
    {
        $updated = $this->settingsService->updateSettings($this->authenticatedUser(), $request->validated());

        return $this->successResponse($updated, __('messages.admin.system_settings_updated'));
    }

    /**
     * POST /api/v1/admin/system/cache/flush
     */
    public function flushCache(): JsonResponse
    {
        $result = $this->settingsService->flushApplicationCache($this->authenticatedUser());

        return $this->successResponse($result, __('messages.admin.cache_flushed'));
    }

    /**
     * GET /api/v1/admin/system/audit-logs
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $result = $this->systemService->listAuditLogs([
            'level' => $request->query('level'),
            'event' => $request->query('event'),
            'user_id' => $request->query('user_id'),
            'ip_address' => $request->query('ip_address'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.audit_logs_fetched'),
            200,
            $result['meta']
        );
    }

    /**
     * GET /api/v1/admin/system/security-summary
     */
    public function securitySummary(): JsonResponse
    {
        return $this->successResponse(
            $this->systemService->getSecuritySummary(),
            __('messages.admin.security_summary_fetched')
        );
    }
}
