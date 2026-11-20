<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\JoinGroupByCodeRequest;
use App\Http\Requests\V1\Student\SaveStudentScheduleNoteRequest;
use App\Http\Requests\V1\Student\SaveStudentStudySessionRequest;
use App\Http\Resources\V1\Teacher\PendingStudentResource;
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

        return $this->createdResponse([
            'id' => $session->uuid,
            'day' => $session->day_of_week->value ?? (string) $session->day_of_week,
            'subject' => $session->subject_ar,
            'title' => $session->title_ar,
            'startTime' => $session->start_time,
            'endTime' => $session->end_time,
            'durationMinutes' => (int) $session->duration_minutes,
            'isCompleted' => (bool) $session->is_completed,
            'color' => $session->color,
            'notes' => $session->notes,
        ], 'تمت إضافة جلسة المذاكرة بنجاح.');
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

        return $this->successResponse([
            'id' => $session->uuid,
            'day' => $session->day_of_week->value ?? (string) $session->day_of_week,
            'subject' => $session->subject_ar,
            'title' => $session->title_ar,
            'startTime' => $session->start_time,
            'endTime' => $session->end_time,
            'durationMinutes' => (int) $session->duration_minutes,
            'isCompleted' => (bool) $session->is_completed,
            'color' => $session->color,
            'notes' => $session->notes,
        ], 'تم تحديث جلسة المذاكرة بنجاح.');
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

        return $this->successResponse([
            'id' => $session->uuid,
            'isCompleted' => (bool) $session->is_completed,
        ]);
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
}
