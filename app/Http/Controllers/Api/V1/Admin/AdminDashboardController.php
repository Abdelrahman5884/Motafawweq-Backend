<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Admin\AdminDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends BaseApiController
{
    public function __construct(
        protected AdminDashboardService $dashboardService
    ) {}

    /**
     * GET /api/v1/admin/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        $fresh = filter_var($request->query('fresh', false), FILTER_VALIDATE_BOOLEAN);
        $data = $this->dashboardService->getOverview($fresh);

        return $this->successResponse($data, __('messages.admin.dashboard_fetched'));
    }

    /**
     * GET /api/v1/admin/dashboard/active-users
     */
    public function activeUsers(Request $request): JsonResponse
    {
        $mode = strtolower(trim((string) $request->query('mode', 'hourly')));
        if (! in_array($mode, ['hourly', 'weekly'], true)) {
            return $this->validationErrorResponse([
                'mode' => [__('validation.in', ['attribute' => 'mode'])],
            ]);
        }

        $data = $this->dashboardService->getActiveUsersTraffic($mode);

        return $this->successResponse($data, __('messages.admin.traffic_fetched'));
    }
}
