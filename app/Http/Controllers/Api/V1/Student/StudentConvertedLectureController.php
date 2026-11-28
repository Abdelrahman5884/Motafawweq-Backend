<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\StudentConvertedLecture;
use App\Services\V1\Student\StudentConvertedLectureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StudentConvertedLectureController extends BaseApiController
{
    public function __construct(
        protected StudentConvertedLectureService $service
    ) {}

    /** GET /api/v1/student/converted-lectures */
    public function index(): JsonResponse
    {
        return $this->successResponse($this->service->list($this->authenticatedUser()));
    }

    /** GET /api/v1/student/converted-lectures/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        return $this->successResponse($this->service->show($this->authenticatedUser(), $uuid));
    }

    /** GET /api/v1/student/converted-lectures/{uuid}/status */
    public function status(string $uuid): JsonResponse
    {
        return $this->successResponse($this->service->getStatus($this->authenticatedUser(), $uuid));
    }

    /** POST /api/v1/student/converted-lectures */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title_ar' => ['nullable', 'string', 'max:255'],
            'subject_ar' => ['nullable', 'string', 'max:120'],
            'grade_ar' => ['nullable', 'string', 'max:120'],
            'lesson_uuid' => ['nullable', 'uuid'],
            'embed_url' => ['nullable', 'url:https', 'max:500'],
            'language' => ['nullable', 'string', 'max:16', 'regex:/^(auto|[a-zA-Z]{2,3})$/'],
            'outputs' => ['nullable', 'array', 'max:4'],
            'outputs.*' => ['string', 'in:graph,transcript,topics,quiz'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_size' => ['nullable', 'string', 'max:60'],
            'file' => ['nullable', 'file', 'max:204800'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:204800'],
        ]);

        $extraFiles = $request->file('files', []);
        if (! is_array($extraFiles)) {
            $extraFiles = $extraFiles ? [$extraFiles] : [];
        }

        $lecture = $this->service->create(
            $this->authenticatedUser(),
            $validated,
            $request->file('file'),
            $extraFiles
        );

        return $this->createdResponse($lecture, 'تم حفظ المحاضرة في أرشيفك بنجاح.');
    }

    /** POST /api/v1/student/converted-lectures/{uuid}/attachments */
    public function addAttachments(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:204800'],
            'reanalyze' => ['nullable', 'boolean'],
        ]);

        $files = $request->file('files', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }

        try {
            $lecture = $this->service->addAttachments(
                $this->authenticatedUser(),
                $uuid,
                $files,
                (bool) ($validated['reanalyze'] ?? false)
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage() ?: __('messages.ai.service_unavailable'),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        return $this->successResponse($lecture, 'تمت إضافة المرفقات والصور للمحاضرة بنجاح.');
    }

    /** DELETE /api/v1/student/converted-lectures/{uuid}/attachments/{attachmentId} */
    public function destroyAttachment(string $uuid, string $attachmentId): JsonResponse
    {
        $lecture = $this->service->deleteAttachment($this->authenticatedUser(), $uuid, $attachmentId);

        return $this->successResponse($lecture, 'تم حذف المرفق من المحاضرة.');
    }

    /** POST /api/v1/student/converted-lectures/{uuid}/retry */
    public function retry(string $uuid): JsonResponse
    {
        try {
            $lecture = $this->service->retry($this->authenticatedUser(), $uuid);
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage() ?: __('messages.ai.service_unavailable'),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        return $this->successResponse(
            $lecture,
            __('messages.ai.converted_lecture_retry_started'),
            Response::HTTP_ACCEPTED
        );
    }

    /** POST /api/v1/student/converted-lectures/{uuid}/quiz */
    public function generateQuiz(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'quiz_count' => ['nullable', 'integer', 'min:2', 'max:10'],
        ]);

        try {
            $lecture = $this->service->generateQuiz(
                $this->authenticatedUser(),
                $uuid,
                (int) ($validated['quiz_count'] ?? 5)
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage() ?: __('messages.ai.service_unavailable'),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        return $this->successResponse(
            $lecture,
            __('messages.ai.quiz_generation_started'),
            Response::HTTP_ACCEPTED
        );
    }

    /** DELETE /api/v1/student/converted-lectures/{uuid} */
    public function destroy(string $uuid): JsonResponse
    {
        $this->service->delete($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, 'تم حذف المحاضرة من أرشيفك.');
    }

    /**
     * GET /api/v1/media/student-converted-lectures/{uuid}/media/stream (signed URL)
     */
    public function streamMedia(Request $request, string $uuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        $lecture = StudentConvertedLecture::where('uuid', $uuid)->firstOrFail();
        $disk = $lecture->media_disk ?: 'local';

        if (! $lecture->media_path || ! Storage::disk($disk)->exists($lecture->media_path)) {
            return $this->notFoundResponse(__('messages.not_found'));
        }

        return $this->streamStoredFile(
            $disk,
            $lecture->media_path,
            $lecture->media_mime_type ?: 'audio/webm',
            $lecture->file_name ?: 'lecture-media',
            $request->boolean('download') ? 'attachment' : 'inline'
        );
    }

    /**
     * GET /api/v1/media/student-converted-lectures/{uuid}/attachments/{attachmentId}/stream (signed URL)
     */
    public function streamAttachment(Request $request, string $uuid, string $attachmentId): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        $lecture = StudentConvertedLecture::where('uuid', $uuid)->firstOrFail();
        $attachments = is_array($lecture->attachments) ? $lecture->attachments : [];
        $target = null;

        foreach ($attachments as $att) {
            if (is_array($att) && ($att['id'] ?? null) === $attachmentId) {
                $target = $att;
                break;
            }
        }

        if (! $target || empty($target['path'])) {
            return $this->notFoundResponse(__('messages.not_found'));
        }

        $disk = (string) ($target['disk'] ?? 'local');
        $path = (string) $target['path'];

        if (! Storage::disk($disk)->exists($path)) {
            return $this->notFoundResponse(__('messages.not_found'));
        }

        return $this->streamStoredFile(
            $disk,
            $path,
            (string) ($target['mimeType'] ?? 'application/octet-stream'),
            (string) ($target['name'] ?? 'attachment'),
            $request->boolean('download') ? 'attachment' : 'inline'
        );
    }

    private function streamStoredFile(
        string $disk,
        string $path,
        string $mimeType,
        string $fileName,
        string $dispositionType
    ): Response {
        $cleanName = str_replace(["\r", "\n", '"', '\\'], '', trim($fileName)) ?: 'file';
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $cleanName) ?: 'file';
        $disposition = sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $dispositionType, $asciiFallback, rawurlencode($cleanName));

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($disk === 'local' || $disk === 'public') {
            return response()->file(Storage::disk($disk)->path($path), $headers);
        }

        return response(
            Storage::disk($disk)->get($path),
            200,
            array_merge($headers, ['Accept-Ranges' => 'bytes'])
        );
    }
}
