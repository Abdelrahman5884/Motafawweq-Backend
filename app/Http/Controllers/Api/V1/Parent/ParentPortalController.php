<?php

namespace App\Http\Controllers\Api\V1\Parent;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Api\V1\Parent\LinkChildRequest;
use App\Http\Requests\Api\V1\Parent\StoreParentInquiryRequest;
use App\Http\Requests\Api\V1\Parent\UpdateParentSettingsRequest;
use App\Services\V1\Parent\ParentPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ParentPortalController extends BaseApiController
{
    public function __construct(
        protected ParentPortalService $parentPortalService
    ) {}

    public function overview(): JsonResponse
    {
        $data = $this->parentPortalService->getOverview($this->authenticatedUser());

        return $this->successResponse($data, __('messages.parent.overview_fetched'));
    }

    public function childDashboard(int $studentId): JsonResponse
    {
        $data = $this->parentPortalService->getChildDashboard($this->authenticatedUser(), $studentId);

        return $this->successResponse($data, __('messages.parent.child_dashboard_fetched'));
    }

    public function childCourses(int $studentId): JsonResponse
    {
        $data = $this->parentPortalService->getChildCourses($this->authenticatedUser(), $studentId);

        return $this->successResponse($data, __('messages.parent.child_courses_fetched'));
    }

    public function childAttendance(Request $request, int $studentId): JsonResponse
    {
        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 25);

        $data = $this->parentPortalService->getChildAttendance(
            $this->authenticatedUser(),
            $studentId,
            is_string($status) ? $status : null,
            $perPage
        );

        return $this->successResponse($data, __('messages.parent.child_attendance_fetched'));
    }

    public function childExams(Request $request, int $studentId): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 25);

        $data = $this->parentPortalService->getChildExams(
            $this->authenticatedUser(),
            $studentId,
            $perPage
        );

        return $this->successResponse($data, __('messages.parent.child_exams_fetched'));
    }

    public function exportChildExams(int $studentId): Response
    {
        $export = $this->parentPortalService->exportChildExamsCsv($this->authenticatedUser(), $studentId);

        return response($export['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function childHomework(Request $request, int $studentId): JsonResponse
    {
        $status = $request->query('status');

        $data = $this->parentPortalService->getChildHomework(
            $this->authenticatedUser(),
            $studentId,
            is_string($status) ? $status : null
        );

        return $this->successResponse($data, __('messages.parent.child_homework_fetched'));
    }

    public function childFinancials(int $studentId): JsonResponse
    {
        $data = $this->parentPortalService->getChildFinancials($this->authenticatedUser(), $studentId);

        return $this->successResponse($data, __('messages.parent.child_financials_fetched'));
    }

    public function linkChild(LinkChildRequest $request): JsonResponse
    {
        $child = $this->parentPortalService->linkChildByCode(
            $this->authenticatedUser(),
            (string) $request->validated('student_code')
        );

        return $this->createdResponse($child, __('messages.parent.child_linked'));
    }

    public function unlinkChild(int $studentId): JsonResponse
    {
        $this->parentPortalService->unlinkChild($this->authenticatedUser(), $studentId);

        return $this->successResponse(null, __('messages.parent.child_unlinked'));
    }

    public function inquiries(Request $request): JsonResponse
    {
        $studentId = $request->query('student_id');
        $perPage = (int) $request->query('per_page', 15);

        $data = $this->parentPortalService->listInquiries(
            $this->authenticatedUser(),
            is_numeric($studentId) ? (int) $studentId : null,
            $perPage
        );

        return $this->successResponse($data, __('messages.parent.inquiries_fetched'));
    }

    public function storeInquiry(StoreParentInquiryRequest $request): JsonResponse
    {
        $inquiry = $this->parentPortalService->createInquiry(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse($inquiry, __('messages.parent.inquiry_created'));
    }

    public function settings(): JsonResponse
    {
        $data = $this->parentPortalService->getSettings($this->authenticatedUser());

        return $this->successResponse($data, __('messages.parent.settings_fetched'));
    }

    public function updateSettings(UpdateParentSettingsRequest $request): JsonResponse
    {
        $data = $this->parentPortalService->updateSettings(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->successResponse($data, __('messages.parent.settings_updated'));
    }
}
