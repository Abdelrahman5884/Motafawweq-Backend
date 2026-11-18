<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Services\V1\Student\StudentCourseLessonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * GET /api/v1/student/lessons/{uuid}/media/stream (signed URL)
     */
    public function streamMedia(Request $request, string $uuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        $lesson = Lesson::where('uuid', $uuid)->firstOrFail();
        $disk = $lesson->media_disk ?? 'local';

        if (! $lesson->media_path || ! Storage::disk($disk)->exists($lesson->media_path)) {
            return $this->notFoundResponse(__('messages.not_found'));
        }

        if ($disk === 'local' || $disk === 'public') {
            return response()->file(
                Storage::disk($disk)->path($lesson->media_path),
                [
                    'Content-Type' => $lesson->media_mime_type ?: 'video/mp4',
                    'Content-Disposition' => 'inline; filename="'.($lesson->media_original_name ?: 'lesson-media').'"',
                    'Cache-Control' => 'private, max-age=3600',
                ]
            );
        }

        if (method_exists(Storage::disk($disk), 'temporaryUrl')) {
            try {
                return redirect()->away(Storage::disk($disk)->temporaryUrl($lesson->media_path, now()->addMinutes(60)));
            } catch (\Throwable) {
                // fall back to direct response below
            }
        }

        return response(
            Storage::disk($disk)->get($lesson->media_path),
            200,
            [
                'Content-Type' => $lesson->media_mime_type ?: 'video/mp4',
                'Content-Disposition' => 'inline; filename="'.($lesson->media_original_name ?: 'lesson-media').'"',
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }

    /**
     * GET /api/v1/student/lessons/{uuid}/attachments/{attachmentUuid}/stream (signed URL)
     */
    public function streamAttachment(Request $request, string $uuid, string $attachmentUuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        $lesson = Lesson::where('uuid', $uuid)->firstOrFail();
        $attachment = LessonAttachment::where('lesson_id', $lesson->id)
            ->where('uuid', $attachmentUuid)
            ->firstOrFail();

        $disk = $attachment->storage_disk ?? 'local';
        if (! $attachment->storage_path || ! Storage::disk($disk)->exists($attachment->storage_path)) {
            return $this->notFoundResponse(__('messages.not_found'));
        }

        if (method_exists(Storage::disk($disk), 'temporaryUrl')) {
            try {
                return redirect()->away(Storage::disk($disk)->temporaryUrl($attachment->storage_path, now()->addMinutes(60)));
            } catch (\Throwable) {
                // fall back to direct response
            }
        }

        return response(
            Storage::disk($disk)->get($attachment->storage_path),
            200,
            [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$attachment->original_filename.'"',
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }
}
