<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\StoreScheduleNoteRequest;
use App\Http\Resources\V1\Teacher\TeacherScheduleNoteResource;
use App\Services\V1\Teacher\TeacherStudyGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TeacherScheduleController extends BaseApiController
{
    public function __construct(
        protected TeacherStudyGroupService $groupService
    ) {}

    /**
     * GET /api/v1/teacher/schedule
     * Retrieve the full weekly timetable, today stats, and daily notes.
     */
    public function index(Request $request): JsonResponse
    {
        $schedule = $this->groupService->getWeeklySchedule(
            $this->authenticatedUser(),
            $request->query('center')
        );

        return $this->successResponse(
            $schedule,
            __('messages.schedule_fetched')
        );
    }

    /**
     * POST /api/v1/teacher/schedule/notes
     * Add a quick daily note to a specific day in the timetable.
     */
    public function storeNote(StoreScheduleNoteRequest $request): JsonResponse
    {
        $note = $this->groupService->createScheduleNote(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse(
            new TeacherScheduleNoteResource($note),
            __('messages.schedule_note_created')
        );
    }

    /**
     * PATCH /api/v1/teacher/schedule/notes/{uuid}/toggle
     * Toggle the done/pending status of a daily note.
     */
    public function toggleNote(string $uuid): JsonResponse
    {
        $note = $this->groupService->toggleScheduleNote(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherScheduleNoteResource($note),
            __('messages.schedule_note_toggled')
        );
    }

    /**
     * DELETE /api/v1/teacher/schedule/notes/{uuid}
     * Delete a daily schedule note.
     */
    public function destroyNote(string $uuid): JsonResponse
    {
        $this->groupService->deleteScheduleNote(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            null,
            __('messages.schedule_note_deleted')
        );
    }
}
