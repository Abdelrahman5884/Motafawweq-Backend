<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Enums\DayOfWeek;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\JoinGroupByCodeRequest;
use App\Http\Requests\V1\Student\SaveStudentScheduleNoteRequest;
use App\Http\Requests\V1\Student\SaveStudentStudySessionRequest;
use App\Http\Resources\V1\Teacher\PendingStudentResource;
use App\Models\StudentStudySession;
use App\Services\V1\Student\StudentGroupScheduleService;
use App\Services\V1\Teacher\TeacherRosterService;
use Illuminate\Http\JsonResponse;

class StudentGroupScheduleController extends BaseApiController
{
    public function __construct(
        protected StudentGroupScheduleService $groupScheduleService,
        protected TeacherRosterService $rosterService
    ) {}

    /**
     * GET /api/v1/student/groups
     */
    public function index(): JsonResponse
    {
        $groups = $this->groupScheduleService->listMyGroups($this->authenticatedUser());

        return $this->successResponse($groups);
    }

    /**
     * POST /api/v1/student/groups/join (and legacy /api/v1/groups/join)
     */
    public function join(JoinGroupByCodeRequest $request): JsonResponse
    {
        $enrollment = $this->rosterService->studentJoinByCode(
            $this->authenticatedUser(),
            $request->validated('join_code'),
            $request->validated()
        );

        return $this->createdResponse(
            new PendingStudentResource($enrollment),
            __('messages.join_request_submitted')
        );
    }

    /**
     * DELETE /api/v1/student/groups/{uuid}/leave
     */
    public function leave(string $uuid): JsonResponse
    {
        $this->groupScheduleService->leaveGroup($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, 'تم مغادرة المجموعة الدراسية بنجاح.');
    }

    /**
     * POST /api/v1/student/groups/invitations/{enrollmentUuid}/accept
     */
    public function acceptInvitation(string $enrollmentUuid): JsonResponse
    {
        $enrollment = $this->rosterService->studentAcceptInvitation(
            $this->authenticatedUser(),
            $enrollmentUuid
        );

        return $this->successResponse([
            'enrollmentId' => $enrollment->uuid,
            'groupId' => $enrollment->group?->uuid,
            'enrollmentStatus' => $enrollment->status?->value ?? (string) $enrollment->status,
            'studentBarcode' => $enrollment->student_barcode,
            'studentPasscode' => $enrollment->student_passcode,
        ], 'تم قبول دعوة المعلم والانضمام للمجموعة بنجاح!');
    }

    /**
     * POST /api/v1/student/groups/invitations/{enrollmentUuid}/decline
     */
    public function declineInvitation(string $enrollmentUuid): JsonResponse
    {
        $this->rosterService->studentDeclineInvitation(
            $this->authenticatedUser(),
            $enrollmentUuid
        );

        return $this->successResponse(null, 'تم رفض دعوة الانضمام للمجموعة.');
    }

    /**
     * GET /api/v1/student/schedule
     */
    public function schedule(): JsonResponse
    {
        $schedule = $this->groupScheduleService->getWeeklySchedule($this->authenticatedUser());

        return $this->successResponse($schedule);
    }

    /**
     * POST /api/v1/student/study-sessions
     */
    public function storeStudySession(SaveStudentStudySessionRequest $request): JsonResponse
    {
        $session = $this->groupScheduleService->createStudySession(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse(
            $this->formatStudySession($session),
            'تمت إضافة جلسة المذاكرة بنجاح.'
        );
    }

    /**
     * PUT /api/v1/student/study-sessions/{uuid}
     */
    public function updateStudySession(SaveStudentStudySessionRequest $request, string $uuid): JsonResponse
    {
        $session = $this->groupScheduleService->updateStudySession(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse(
            $this->formatStudySession($session),
            'تم تحديث جلسة المذاكرة بنجاح.'
        );
    }

    /**
     * PATCH /api/v1/student/study-sessions/{uuid}/toggle
     */
    public function toggleStudySession(string $uuid): JsonResponse
    {
        $session = $this->groupScheduleService->toggleStudySession(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse($this->formatStudySession($session));
    }

    /**
     * DELETE /api/v1/student/study-sessions/{uuid}
     */
    public function destroyStudySession(string $uuid): JsonResponse
    {
        $this->groupScheduleService->deleteStudySession(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(null, 'تم حذف جلسة المذاكرة بنجاح.');
    }

    /**
     * GET /api/v1/student/attendance
     */
    public function attendance(): JsonResponse
    {
        $data = $this->groupScheduleService->getAttendanceAndBarcode($this->authenticatedUser());

        return $this->successResponse($data);
    }

    /**
     * POST /api/v1/student/schedule/notes
     */
    public function storeScheduleNote(SaveStudentScheduleNoteRequest $request): JsonResponse
    {
        $note = $this->groupScheduleService->createScheduleNote(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse($note, 'تم حفظ الملحوظة بنجاح.');
    }

    /**
     * PUT /api/v1/student/schedule/notes/{uuid}
     */
    public function updateScheduleNote(SaveStudentScheduleNoteRequest $request, string $uuid): JsonResponse
    {
        $note = $this->groupScheduleService->updateScheduleNote(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse($note, 'تم تحديث الملحوظة بنجاح.');
    }

    /**
     * PATCH /api/v1/student/schedule/notes/{uuid}/toggle
     */
    public function toggleScheduleNote(string $uuid): JsonResponse
    {
        $note = $this->groupScheduleService->toggleScheduleNote(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse($note);
    }

    /**
     * DELETE /api/v1/student/schedule/notes/{uuid}
     */
    public function destroyScheduleNote(string $uuid): JsonResponse
    {
        $this->groupScheduleService->deleteScheduleNote(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(null, 'تم حذف الملحوظة بنجاح.');
    }

    /**
     * Format a StudentStudySession consistently for API JSON responses.
     *
     * @return array<string, mixed>
     */
    private function formatStudySession(StudentStudySession $session): array
    {
        return [
            'id' => $session->uuid,
            'uuid' => $session->uuid,
            'type' => 'study_session',
            'day' => $session->day_of_week instanceof DayOfWeek
                ? $session->day_of_week->value
                : strtolower((string) $session->day_of_week),
            'subject' => $session->subject_ar,
            'title' => $session->title_ar,
            'startTime' => $session->start_time,
            'endTime' => $session->end_time,
            'durationMinutes' => (int) $session->duration_minutes,
            'isCompleted' => (bool) $session->is_completed,
            'color' => $session->color ?: '#1588C7',
            'notes' => $session->notes,
        ];
    }
}
