<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\EnrollCenterStudentRequest;
use App\Http\Requests\V1\Center\StoreCenterGroupRequest;
use App\Http\Requests\V1\Center\UpdateCenterGroupRequest;
use App\Services\V1\Center\CenterGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CenterGroupController extends BaseApiController
{
    public function __construct(
        protected CenterGroupService $groupService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->groupService->listGroups(
            $this->authenticatedUser(),
            $request->only(['branch_id', 'room_id', 'teacher_id', 'subject', 'status', 'search'])
        );

        return $this->successResponse(
            $result['groups'],
            __('messages.groups_fetched'),
            meta: $result['summary']
        );
    }

    public function store(StoreCenterGroupRequest $request): JsonResponse
    {
        $group = $this->groupService->createGroup(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse($group, __('messages.group_created'));
    }

    public function show(string $uuid): JsonResponse
    {
        $group = $this->groupService->findOwnedGroup($this->authenticatedUser(), $uuid);

        return $this->successResponse(
            $this->groupService->formatGroup($group),
            __('messages.group_fetched')
        );
    }

    public function update(UpdateCenterGroupRequest $request, string $uuid): JsonResponse
    {
        $group = $this->groupService->updateGroup(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse($group, __('messages.group_updated'));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->groupService->deleteGroup($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.group_deleted'));
    }

    public function regenerateCode(string $uuid): JsonResponse
    {
        $group = $this->groupService->regenerateJoinCode($this->authenticatedUser(), $uuid);

        return $this->successResponse($group, __('messages.group_code_regenerated'));
    }

    public function roster(Request $request, string $uuid): JsonResponse
    {
        $data = $this->groupService->getGroupRoster(
            $this->authenticatedUser(),
            $uuid,
            $request->query('search'),
            $request->query('status'),
            $request->query('date')
        );

        return $this->successResponse($data, __('messages.roster_fetched'));
    }

    public function searchStudents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:40'],
        ]);

        $students = $this->groupService->searchPlatformStudents(
            $this->authenticatedUser(),
            (string) ($validated['q'] ?? ''),
            (int) ($validated['limit'] ?? 20)
        );

        return $this->successResponse(
            $students,
            __('messages.students_searched'),
            meta: ['total' => count($students)]
        );
    }

    public function enrollStudent(EnrollCenterStudentRequest $request, string $uuid): JsonResponse
    {
        $enrolled = $this->groupService->enrollStudent(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        $message = ! empty($enrolled['isInvitation'])
            ? 'تم إرسال دعوة الانضمام للطالب بنجاح! بانتظار قبول الطالب من حسابه.'
            : __('messages.center.student_enrolled');

        return $this->createdResponse($enrolled, $message);
    }

    public function acceptPending(string $uuid, string $enrollmentUuid): JsonResponse
    {
        $enrolled = $this->groupService->acceptPendingEnrollment(
            $this->authenticatedUser(),
            $uuid,
            $enrollmentUuid
        );

        return $this->successResponse($enrolled, __('messages.student_accepted'));
    }

    public function rejectPending(string $uuid, string $enrollmentUuid): JsonResponse
    {
        $this->groupService->rejectPendingEnrollment(
            $this->authenticatedUser(),
            $uuid,
            $enrollmentUuid
        );

        return $this->successResponse(null, __('messages.student_rejected'));
    }

    public function removeStudent(string $uuid, string $enrollmentUuid): JsonResponse
    {
        $this->groupService->removeStudent(
            $this->authenticatedUser(),
            $uuid,
            $enrollmentUuid
        );

        return $this->successResponse(null, __('messages.student_removed'));
    }

    public function updatePayment(Request $request, string $uuid, string $enrollmentUuid): JsonResponse
    {
        $request->merge(array_filter([
            'payment_status' => $request->input('payment_status', $request->input('paymentStatus')),
            'due_amount_egp' => $request->input('due_amount_egp', $request->input('dueAmountEgp')),
            'payment_due_since' => $request->input('payment_due_since', $request->input('paymentDueSince')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'payment_status' => ['required', 'string', Rule::in(['paid', 'partial', 'due'])],
            'due_amount_egp' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'payment_due_since' => ['nullable', 'date'],
        ]);

        $updated = $this->groupService->updateEnrollmentPayment(
            $this->authenticatedUser(),
            $uuid,
            $enrollmentUuid,
            $validated
        );

        return $this->successResponse($updated, __('messages.center.enrollment_payment_updated'));
    }

    public function studentCard(string $uuid, string $enrollmentUuid): JsonResponse
    {
        $card = $this->groupService->getStudentCard(
            $this->authenticatedUser(),
            $uuid,
            $enrollmentUuid
        );

        return $this->successResponse($card, __('messages.center.student_card_fetched'));
    }
}
