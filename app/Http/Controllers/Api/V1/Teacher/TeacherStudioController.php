<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Enums\LessonAttachmentType;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\ReorderLessonAttachmentsRequest;
use App\Http\Requests\V1\Teacher\StoreTeacherLessonRequest;
use App\Http\Requests\V1\Teacher\UpdateTeacherLessonRequest;
use App\Http\Requests\V1\Teacher\UploadLessonAttachmentRequest;
use App\Http\Requests\V1\Teacher\UploadLessonMediaRequest;
use App\Http\Resources\V1\Teacher\LessonAttachmentResource;
use App\Http\Resources\V1\Teacher\TeacherLessonResource;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Repositories\Contracts\LessonRepositoryInterface;
use App\Services\V1\Teacher\TeacherStudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class TeacherStudioController extends BaseApiController
{
    public function __construct(
        protected TeacherStudioService $studioService,
        protected LessonRepositoryInterface $lessonRepository
    ) {}

    /**
     * GET /api/v1/teacher/lessons
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $this->authenticatedUser();
        $lessons = $this->studioService->listLessons(
            $teacher,
            $request->only(['course_id', 'course_uuid', 'status', 'search', 'q'])
        );

        return $this->successResponse(
            TeacherLessonResource::collection($lessons),
            __('messages.lessons_fetched'),
            meta: $this->studioService->getLessonsMeta($teacher, $lessons->count())
        );
    }

    /**
     * GET /api/v1/teacher/lessons/{uuid}
     */
    public function show(string $uuid): JsonResponse
    {
        $lesson = $this->studioService->getLesson(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherLessonResource($lesson),
            __('messages.lesson_fetched')
        );
    }

    /**
     * POST /api/v1/teacher/lessons
     */
    public function store(StoreTeacherLessonRequest $request): JsonResponse
    {
        $lesson = $this->studioService->createLesson(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse(
            new TeacherLessonResource($lesson),
            __('messages.lesson_created')
        );
    }

    /**
     * PUT /api/v1/teacher/lessons/{uuid}
     */
    public function update(UpdateTeacherLessonRequest $request, string $uuid): JsonResponse
    {
        $lesson = $this->studioService->updateLesson(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse(
            new TeacherLessonResource($lesson),
            __('messages.lesson_updated')
        );
    }

    /**
     * DELETE /api/v1/teacher/lessons/{uuid}
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->studioService->deleteLesson(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            null,
            __('messages.lesson_deleted')
        );
    }

    /**
     * POST /api/v1/teacher/lessons/{uuid}/media
     */
    public function uploadMedia(UploadLessonMediaRequest $request, string $uuid): JsonResponse
    {
        @set_time_limit(300);
        $duration = $request->input('duration_seconds') ?? $request->input('durationSeconds');

        $lesson = $this->studioService->uploadMedia(
            $this->authenticatedUser(),
            $uuid,
            $request->file('media'),
            $request->input('mode'),
            $duration !== null ? (int) $duration : null
        );

        return $this->successResponse(
            new TeacherLessonResource($lesson),
            __('messages.lesson_media_uploaded')
        );
    }

    /**
     * POST /api/v1/teacher/lessons/{uuid}/attachments/pdfs
     */
    public function uploadPdf(UploadLessonAttachmentRequest $request, string $uuid): JsonResponse
    {
        @set_time_limit(300);
        $attachment = $this->studioService->uploadAttachment(
            $this->authenticatedUser(),
            $uuid,
            $request->file('file'),
            LessonAttachmentType::PDF,
            $request->validated()
        );

        $attachment->load('lesson');

        return $this->createdResponse(
            new LessonAttachmentResource($attachment),
            __('messages.lesson_pdf_uploaded')
        );
    }

    /**
     * POST /api/v1/teacher/lessons/{uuid}/attachments/images
     */
    public function uploadImage(UploadLessonAttachmentRequest $request, string $uuid): JsonResponse
    {
        @set_time_limit(300);
        $attachment = $this->studioService->uploadAttachment(
            $this->authenticatedUser(),
            $uuid,
            $request->file('file'),
            LessonAttachmentType::WHITEBOARD_IMAGE,
            $request->validated()
        );

        $attachment->load('lesson');

        return $this->createdResponse(
            new LessonAttachmentResource($attachment),
            __('messages.lesson_image_uploaded')
        );
    }

    /**
     * PUT /api/v1/teacher/lessons/{uuid}/attachments/reorder
     */
    public function reorderAttachments(ReorderLessonAttachmentsRequest $request, string $uuid): JsonResponse
    {
        $attachments = $this->studioService->reorderAttachments(
            $this->authenticatedUser(),
            $uuid,
            $request->validated('ordered_ids'),
            $request->validated('type')
        );

        return $this->successResponse(
            LessonAttachmentResource::collection($attachments),
            __('messages.lesson_attachments_reordered')
        );
    }

    /**
     * DELETE /api/v1/teacher/lessons/{uuid}/attachments/{attachmentUuid}
     */
    public function destroyAttachment(string $uuid, string $attachmentUuid): JsonResponse
    {
        $this->studioService->deleteAttachment(
            $this->authenticatedUser(),
            $uuid,
            $attachmentUuid
        );

        return $this->successResponse(
            null,
            __('messages.lesson_attachment_deleted')
        );
    }

    /**
     * GET /api/v1/teacher/lessons/{uuid}/attachments/{attachmentUuid}/stream
     * Stream a protected PDF or whiteboard image via a temporary signed URL.
     */
    public function streamAttachment(Request $request, string $uuid, string $attachmentUuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        $teacher = $request->user('sanctum');
        if ($teacher) {
            $lesson = $this->lessonRepository->findByUuidForTeacher($uuid, $teacher);
            $attachment = $this->lessonRepository->findAttachmentByUuidForTeacher($attachmentUuid, $lesson, $teacher);
        } else {
            $lesson = Lesson::where('uuid', $uuid)->firstOrFail();
            $attachment = LessonAttachment::where('uuid', $attachmentUuid)
                ->where('lesson_id', $lesson->id)
                ->firstOrFail();
        }

        $disk = $this->resolveExistingDisk($attachment->storage_disk, $attachment->storage_path);
        if (! $disk) {
            return $this->notFoundResponse(__('messages.not_found'));
        }

        if ($request->query('stream_type') === 'redirect' && method_exists(Storage::disk($disk), 'temporaryUrl')) {
            return redirect()->away(Storage::disk($disk)->temporaryUrl($attachment->storage_path, now()->addMinutes(60)));
        }

        return $this->streamStoredFile(
            $disk,
            $attachment->storage_path,
            $attachment->mime_type ?: 'application/octet-stream',
            $attachment->original_file_name ?: 'attachment',
            $request->boolean('download')
        );
    }

    /**
     * GET /api/v1/teacher/lessons/{uuid}/media/stream
     * Stream a protected audio/video recording via a temporary signed URL.
     */
    public function streamMedia(Request $request, string $uuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        $teacher = $request->user('sanctum');
        if ($teacher) {
            $lesson = $this->lessonRepository->findByUuidForTeacher($uuid, $teacher);
        } else {
            $lesson = Lesson::where('uuid', $uuid)->firstOrFail();
        }

        $disk = $this->resolveExistingDisk($lesson->media_disk, $lesson->media_path);
        if (! $disk || ! $lesson->media_path) {
            return $this->notFoundResponse(__('messages.not_found'));
        }

        if ($request->query('stream_type') === 'redirect' && method_exists(Storage::disk($disk), 'temporaryUrl')) {
            return redirect()->away(Storage::disk($disk)->temporaryUrl($lesson->media_path, now()->addMinutes(60)));
        }

        return $this->streamStoredFile(
            $disk,
            $lesson->media_path,
            $lesson->media_mime_type ?? 'video/mp4',
            $lesson->media_original_name ?? 'lesson.mp4',
            $request->boolean('download')
        );
    }

    private function resolveExistingDisk(?string $declaredDisk, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $primary = $declaredDisk ?: 'local';
        $candidates = $primary === 'local' ? ['local'] : [$primary, 'local'];

        foreach ($candidates as $candidate) {
            try {
                if (Storage::disk($candidate)->exists($path)) {
                    return $candidate;
                }
            } catch (\Throwable) {
                // try fallback candidate
            }
        }

        return null;
    }

    private function streamStoredFile(
        string $disk,
        string $path,
        string $mimeType,
        string $originalName,
        bool $forceDownload = false
    ): Response {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $displayName = trim($originalName) ?: 'file';
        if ($ext && ! str_contains($displayName, '.')) {
            $displayName .= '.'.$ext;
        }

        $asciiFallback = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $displayName) ?: ('file'.($ext ? '.'.$ext : ''));
        $asciiFallback = trim($asciiFallback, " ._\t\n\r") ?: ('file'.($ext ? '.'.$ext : ''));
        $disposition = sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $forceDownload ? 'attachment' : 'inline',
            str_replace(['"', '\\'], '', $asciiFallback),
            rawurlencode($displayName)
        );

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($disk === 'local') {
            return response()->file(Storage::disk('local')->path($path), $headers);
        }

        $stream = Storage::disk($disk)->readStream($path);
        if (is_resource($stream)) {
            return response()->stream(function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }, Response::HTTP_OK, $headers);
        }

        return response(
            (string) Storage::disk($disk)->get($path),
            Response::HTTP_OK,
            $headers
        );
    }
}
