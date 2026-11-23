<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Admin\AdminAnnouncementService;
use App\Services\V1\Admin\AdminChampionService;
use App\Services\V1\Admin\AdminFinancialService;
use App\Services\V1\Admin\PlatformSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformPublicController extends BaseApiController
{
    public function __construct(
        protected AdminChampionService $championService,
        protected AdminAnnouncementService $announcementService,
        protected AdminFinancialService $financialService,
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * GET /api/v1/public/champions
     */
    public function champions(): JsonResponse
    {
        return $this->successResponse(
            $this->championService->listPublicPinned(),
            __('messages.data_retrieved')
        );
    }

    /**
     * GET /api/v1/public/announcements
     */
    public function announcements(Request $request): JsonResponse
    {
        $audience = $request->query('audience') ? strtolower(trim((string) $request->query('audience'))) : null;

        return $this->successResponse(
            $this->announcementService->listPublicActive($audience),
            __('messages.data_retrieved')
        );
    }

    /**
     * GET /api/v1/public/pricing-plans
     */
    public function pricingPlans(Request $request): JsonResponse
    {
        $role = $request->query('role') ? strtolower(trim((string) $request->query('role'))) : null;

        return $this->successResponse(
            $this->financialService->listPublicPlans($role),
            __('messages.data_retrieved')
        );
    }

    /**
     * GET /api/v1/public/status
     */
    public function status(): JsonResponse
    {
        return $this->successResponse([
            'maintenanceMode' => $this->settingsService->isMaintenanceMode(),
            'allowTeacherRegistration' => $this->settingsService->allowTeacherRegistration(),
            'autoCertificates' => (bool) $this->settingsService->get('auto_certificates', true),
        ], __('messages.data_retrieved'));
    }
}
