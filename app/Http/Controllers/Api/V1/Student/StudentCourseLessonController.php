<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\RecordLessonProgressRequest;
use App\Http\Requests\V1\Student\SaveLessonNoteRequest;
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

    public function recordProgress(RecordLessonProgressRequest $request, string $uuid): JsonResponse
    {
        $progress = $this->courseLessonService->recordProgressHeartbeat(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse($progress);
    }

    public function toggleComplete(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'is_completed' => ['nullable', 'boolean'],
        ]);

        $targetCompleted = array_key_exists('is_completed', $validated) && $validated['is_completed'] !== null
            ? (bool) $validated['is_completed']
            : null;

        $result = $this->courseLessonService->toggleComplete(
            $this->authenticatedUser(),
            $uuid,
            $targetCompleted
        );

        $message = $result['isCompleted']
            ? 'تم تسجيل إتمام الحصة بنجاح.'
            : 'تم إرجاع حالة الحصة إلى قيد المذاكرة.';

        return $this->successResponse($result, $message);
    }

    public function toggleBookmark(string $uuid): JsonResponse
    {
        $result = $this->courseLessonService->toggleBookmark($this->authenticatedUser(), $uuid);

        return $this->successResponse($result);
    }

    public function storeNote(SaveLessonNoteRequest $request, string $uuid): JsonResponse
    {
        $note = $this->courseLessonService->createLessonNote(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->createdResponse($note, 'تم حفظ الملاحظة بنجاح.');
    }

    public function updateNote(SaveLessonNoteRequest $request, string $noteUuid): JsonResponse
    {
        $note = $this->courseLessonService->updateLessonNote(
            $this->authenticatedUser(),
            $noteUuid,
            $request->validated()
        );

        return $this->successResponse($note, 'تم تحديث الملاحظة بنجاح.');
    }

    public function destroyNote(string $noteUuid): JsonResponse
    {
        $this->courseLessonService->deleteLessonNote($this->authenticatedUser(), $noteUuid);

        return $this->successResponse(null, 'تم حذف الملاحظة بنجاح.');
    }

    /**
     * GET /api/v1/media/student-lessons/{uuid}/media/stream (signed URL)
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

        $headers = [
            'Content-Type' => $lesson->media_mime_type ?: 'video/mp4',
            'Content-Disposition' => $this->buildContentDisposition('inline', $lesson->media_original_name ?: 'lesson-media'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($disk === 'local' || $disk === 'public') {
            return response()->file(Storage::disk($disk)->path($lesson->media_path), $headers);
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
            array_merge($headers, ['Accept-Ranges' => 'bytes'])
        );
    }

    /**
     * GET /api/v1/media/student-lessons/{uuid}/attachments/{attachmentUuid}/stream (signed URL)
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

        $dispositionType = $request->boolean('download') ? 'attachment' : 'inline';
        $filename = $attachment->original_file_name ?: ($attachment->title ?: 'lesson-attachment');
        $headers = [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => $this->buildContentDisposition($dispositionType, $filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($disk === 'local' || $disk === 'public') {
            return response()->file(Storage::disk($disk)->path($attachment->storage_path), $headers);
        }

        if (method_exists(Storage::disk($disk), 'temporaryUrl')) {
            try {
                return redirect()->away(Storage::disk($disk)->temporaryUrl($attachment->storage_path, now()->addMinutes(60)));
            } catch (\Throwable) {
                // fall back to direct response below
            }
        }

        return response(
            Storage::disk($disk)->get($attachment->storage_path),
            200,
            $headers
        );
    }

    /**
     * Build an RFC 5987 safe Content-Disposition header supporting Arabic & Unicode filenames.
     */
    private function buildContentDisposition(string $type, string $filename): string
    {
        $clean = str_replace(["\r", "\n", '"', '\\'], '', trim($filename)) ?: 'file';
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $clean) ?: 'file';

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $asciiFallback, rawurlencode($clean));
    }
}
