<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Student\StudentDashboardService;
use Illuminate\Http\JsonResponse;

class StudentDashboardController extends BaseApiController
{
    public function __construct(
        protected StudentDashboardService $dashboardService
    ) {}

    public function dashboard(): JsonResponse
    {
        return $this->successResponse($this->dashboardService->getDashboard($this->authenticatedUser()));
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
