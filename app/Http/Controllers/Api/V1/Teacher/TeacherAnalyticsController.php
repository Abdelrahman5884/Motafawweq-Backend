<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\ResolveRiskSignalRequest;
use App\Http\Resources\V1\Teacher\StudentRiskSignalResource;
use App\Services\V1\Teacher\TeacherAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherAnalyticsController extends BaseApiController
{
    public function __construct(
        protected TeacherAnalyticsService $analyticsService
    ) {}

    /**
     * GET /api/v1/teacher/analytics/overview
     * High-level cohort KPIs (overall GPA, attendance, revenue, audited count).
     */
    public function overview(Request $request): JsonResponse
    {
        $overview = $this->analyticsService->getOverview(
            $this->authenticatedUser(),
            $request->query('course_id')
        );

        return $this->successResponse(
            $overview,
            __('messages.analytics_overview_fetched')
        );
    }

    /**
     * GET /api/v1/teacher/analytics/weak-areas
     * Deep diagnostic analysis of most missed questions & topics.
     */
    public function weakAreas(Request $request): JsonResponse
    {
        $weakAreas = $this->analyticsService->getWeakAreas(
            $this->authenticatedUser(),
            $request->query('course_id')
        );

        return $this->successResponse(
            $weakAreas,
            __('messages.weak_areas_fetched'),
            meta: ['total' => count($weakAreas)]
        );
    }

    /**
     * GET /api/v1/teacher/analytics/at-risk-radar
     * Return list of students exhibiting academic drop or attendance warning signs.
     */
    public function atRiskRadar(Request $request): JsonResponse
    {
        $signals = $this->analyticsService->getAtRiskRadar(
            $this->authenticatedUser(),
            $request->only(['risk_level', 'level', 'status'])
        );

        return $this->successResponse(
            StudentRiskSignalResource::collection($signals),
            __('messages.at_risk_radar_fetched'),
            meta: ['total' => $signals->count()]
        );
    }

    /**
     * POST /api/v1/teacher/analytics/at-risk/{uuid}/resolve
     * Acknowledge, notify or resolve student risk signal.
     */
    public function resolveRiskSignal(ResolveRiskSignalRequest $request, string $uuid): JsonResponse
    {
        $signal = $this->analyticsService->resolveRiskSignal(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse(
            new StudentRiskSignalResource($signal),
            __('messages.risk_signal_updated')
        );
    }

    /**
     * GET /api/v1/teacher/dashboard
     * Live aggregated dashboard summary for TeacherDashboard.jsx.
     */
    public function dashboard(): JsonResponse
    {
        $dashboardData = $this->analyticsService->getDashboardSummary(
            $this->authenticatedUser()
        );

        return $this->successResponse(
            $dashboardData,
            __('messages.dashboard_summary_fetched')
        );
    }
}

