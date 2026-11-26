<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\AI\LessonSpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class TeacherLessonAiController extends BaseApiController
{
    public function __construct(
        protected LessonSpeechToTextService $sttService
    ) {}

    /**
     * GET /api/v1/teacher/workspace/lessons/{uuid}/ai/status
     * Fetch live Speech-to-Text processing status, detected language, and persisted transcript segments.
     */
    public function status(string $uuid): JsonResponse
    {
        $payload = $this->sttService->getStatusForLesson(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse($payload, __('messages.ai.status_fetched'));
    }

    /**
     * POST /api/v1/teacher/workspace/lessons/{uuid}/ai/transcribe
     * Trigger (or re-run) AI Speech-to-Text transcription on a teacher's lesson media.
     */
    public function transcribe(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'language' => ['nullable', 'string', 'max:16', 'regex:/^(auto|[a-zA-Z]{2,3})$/'],
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $job = $this->sttService->startForLesson(
                $this->authenticatedUser(),
                $uuid,
                $validated['language'] ?? null,
                (bool) ($validated['force'] ?? false)
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage() ?: __('messages.ai.service_unavailable'),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $statusCode = $job->status === 'completed'
            ? Response::HTTP_OK
            : Response::HTTP_ACCEPTED;

        return $this->successResponse(
            [
                'job' => LessonSpeechToTextService::formatJob($job),
            ],
            $job->status === 'completed'
                ? __('messages.ai.transcription_completed')
                : __('messages.ai.transcription_started'),
            $statusCode
        );
    }
}
