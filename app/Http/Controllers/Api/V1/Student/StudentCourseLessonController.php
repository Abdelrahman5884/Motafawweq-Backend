<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Student\StudentCourseLessonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentCourseLessonController extends BaseApiController
{
    public function __construct(
        protected StudentCourseLessonService $courseLessonService
    ) {}

    public function courses(): JsonResponse
    {
        $courses = $this->courseLessonService->listCourses($this->authenticatedUser());

        return $this->successResponse($courses);
    }

    public function showCourse(string $uuid): JsonResponse
    {
        $course = $this->courseLessonService->getCourse($this->authenticatedUser(), $uuid);

        return $this->successResponse($course);
    }

    public function showLesson(string $uuid): JsonResponse
    {
        $lesson = $this->courseLessonService->getLesson($this->authenticatedUser(), $uuid);

        return $this->successResponse($lesson);
    }

    public function recordProgress(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:14400'],
            'watched_delta_seconds' => ['nullable', 'integer', 'min:0', 'max:60'],
            'duration_seconds' => ['nullable', 'integer', 'min:60', 'max:14400'],
        ]);

        $progress = $this->courseLessonService->recordProgressHeartbeat(
            $this->authenticatedUser(),
            $uuid,
            $validated
        );

        return $this->successResponse($progress);
    }

    public function toggleBookmark(string $uuid): JsonResponse
    {
        $result = $this->courseLessonService->toggleBookmark($this->authenticatedUser(), $uuid);

        return $this->successResponse($result);
    }

    public function storeNote(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'timestamp_seconds' => ['nullable', 'integer', 'min:0', 'max:14400'],
            'content' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $note = $this->courseLessonService->createLessonNote(
            $this->authenticatedUser(),
            $uuid,
            $validated
        );

        return $this->createdResponse($note, 'تم حفظ الملاحظة بنجاح.');
    }

    public function destroyNote(string $noteUuid): JsonResponse
    {
        $this->courseLessonService->deleteLessonNote($this->authenticatedUser(), $noteUuid);

        return $this->successResponse(null, 'تم حذف الملاحظة بنجاح.');
    }
}
