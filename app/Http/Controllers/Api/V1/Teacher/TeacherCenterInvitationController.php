<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Teacher\TeacherCenterInvitationService;
use Illuminate\Http\JsonResponse;

class TeacherCenterInvitationController extends BaseApiController
{
    public function __construct(
        protected TeacherCenterInvitationService $invitationService
    ) {}

    /**
     * GET /api/v1/teacher/center-invitations
     */
    public function index(): JsonResponse
    {
        $result = $this->invitationService->listForTeacher($this->authenticatedUser());

        return $this->successResponse(
            $result,
            'تم تحميل دعوات السناتر بنجاح.',
            meta: [
                'pendingCount' => count($result['invitations']),
                'activeCentersCount' => count($result['activeCenters']),
            ]
        );
    }

    /**
     * POST /api/v1/teacher/center-invitations/{uuid}/accept
     */
    public function accept(string $uuid): JsonResponse
    {
        $accepted = $this->invitationService->acceptInvitation($this->authenticatedUser(), $uuid);

        return $this->successResponse(
            $accepted,
            'تم قبول دعوة الانضمام للسنتر بنجاح.'
        );
    }

    /**
     * POST /api/v1/teacher/center-invitations/{uuid}/decline
     */
    public function decline(string $uuid): JsonResponse
    {
        $declined = $this->invitationService->declineInvitation($this->authenticatedUser(), $uuid);

        return $this->successResponse(
            $declined,
            'تم رفض دعوة الانضمام للسنتر.'
        );
    }
}
