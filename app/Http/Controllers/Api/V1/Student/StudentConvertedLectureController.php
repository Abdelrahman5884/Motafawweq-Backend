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
        ]);

        $lecture = $this->service->create(
            $this->authenticatedUser(),
            $validated,
            $request->file('file')
        );

        return $this->createdResponse($lecture, 'تم حفظ المحاضرة في أرشيفك بنجاح.');
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

        $cleanName = str_replace(["\r", "\n", '"', '\\'], '', trim((string) ($lecture->file_name ?: 'lecture-media'))) ?: 'lecture-media';
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $cleanName) ?: 'lecture-media';
        $disposition = sprintf('inline; filename="%s"; filename*=UTF-8\'\'%s', $asciiFallback, rawurlencode($cleanName));

        $headers = [
            'Content-Type' => $lecture->media_mime_type ?: 'audio/webm',
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($disk === 'local' || $disk === 'public') {
            return response()->file(Storage::disk($disk)->path($lecture->media_path), $headers);
        }

        return response(
            Storage::disk($disk)->get($lecture->media_path),
            200,
            array_merge($headers, ['Accept-Ranges' => 'bytes'])
        );
    }
}
