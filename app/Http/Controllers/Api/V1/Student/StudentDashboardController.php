<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\RecordActivityHeartbeatRequest;
use App\Services\V1\Student\StudentActivityService;
use App\Services\V1\Student\StudentDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDashboardController extends BaseApiController
{
    public function __construct(
        protected StudentDashboardService $dashboardService,
        protected StudentActivityService $activityService
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $timezone = is_string($request->query('timezone')) ? $request->query('timezone') : null;

        return $this->successResponse(
            $this->dashboardService->getDashboard($this->authenticatedUser(), $timezone)
        );
    }

    /**
     * POST /api/v1/student/activity/heartbeat
     * Records daily presence (updating consecutive streak) and wall-clock-verified active platform seconds.
     */
    public function heartbeat(RecordActivityHeartbeatRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $snapshot = $this->activityService->recordHeartbeat(
            $this->authenticatedUser(),
            (int) ($validated['delta_seconds'] ?? 0),
            $validated['timezone'] ?? null,
            (bool) ($validated['is_check_in'] ?? false)
        );

        return $this->successResponse($snapshot);
    }

    public function analytics(): JsonResponse
    {
        return $this->successResponse($this->dashboardService->getAnalytics($this->authenticatedUser()));
    }

    public function revision(): JsonResponse
    {
        return $this->successResponse($this->dashboardService->getRevisionHub($this->authenticatedUser()));
    }
}
