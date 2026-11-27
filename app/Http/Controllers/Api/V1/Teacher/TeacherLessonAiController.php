<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\AI\LessonAiPipelineService;
use App\Services\V1\AI\LessonSpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class TeacherLessonAiController extends BaseApiController
{
    public function __construct(
        protected LessonSpeechToTextService $sttService,
        protected LessonAiPipelineService $pipelineService
    ) {}

    /**
     * GET /api/v1/teacher/workspace/lessons/{uuid}/ai/status
     * Fetch live unified AI pipeline status (STT + Chapter Indexing + NotebookLM Concept Graph).
     */
    public function status(string $uuid): JsonResponse
    {
        $payload = $this->pipelineService->getStatusForLesson(
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

    /**
     * POST /api/v1/teacher/workspace/lessons/{uuid}/ai/process
     * Launch the unified multi-stage AI pipeline (Speech-to-Text -> Chapters -> NotebookLM Concept Graph).
     */
    public function process(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'features' => ['nullable', 'array'],
            'features.speechToText' => ['nullable', 'boolean'],
            'features.chapterIndexing' => ['nullable', 'boolean'],
            'features.conceptGraph' => ['nullable', 'boolean'],
            'language' => ['nullable', 'string', 'max:16', 'regex:/^(auto|[a-zA-Z]{2,3})$/'],
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $payload = $this->pipelineService->startPipeline(
                $this->authenticatedUser(),
                $uuid,
                $validated['features'] ?? null,
                $validated['language'] ?? null,
                (bool) ($validated['force'] ?? false)
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage() ?: __('messages.ai.service_unavailable'),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $statusCode = ($payload['overallStatus'] ?? '') === 'completed'
            ? Response::HTTP_OK
            : Response::HTTP_ACCEPTED;

        return $this->successResponse(
            $payload,
            __('messages.ai.pipeline_started'),
            $statusCode
        );
    }

    /**
     * POST /api/v1/teacher/workspace/lessons/{uuid}/ai/chapters
     * Generate timestamped study chapters using AI (auto-transcribes first if needed).
     */
    public function generateChapters(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $payload = $this->pipelineService->startAnalysisTasks(
                $this->authenticatedUser(),
                $uuid,
                ['chapters'],
                (bool) ($validated['force'] ?? true)
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage() ?: __('messages.ai.service_unavailable'),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        return $this->successResponse(
            $payload,
            __('messages.ai.chapters_generation_started'),
            Response::HTTP_ACCEPTED
        );
    }

    /**
     * POST /api/v1/teacher/workspace/lessons/{uuid}/ai/concept-graph
     * Generate an interactive NotebookLM Concept Graph using AI (auto-transcribes first if needed).
     */
    public function generateConceptGraph(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $payload = $this->pipelineService->startAnalysisTasks(
                $this->authenticatedUser(),
                $uuid,
                ['concept_graph'],
                (bool) ($validated['force'] ?? true)
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage() ?: __('messages.ai.service_unavailable'),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        return $this->successResponse(
            $payload,
            __('messages.ai.concept_graph_generation_started'),
            Response::HTTP_ACCEPTED
        );
    }
}
