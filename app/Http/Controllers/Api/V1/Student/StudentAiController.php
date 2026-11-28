<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Student\StudentAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StudentAiController extends BaseApiController
{
    public function __construct(
        protected StudentAiService $aiService
    ) {}

    /** GET /api/v1/student/ai/lessons */
    public function accessibleLessons(): JsonResponse
    {
        return $this->successResponse($this->aiService->listAccessibleLessons($this->authenticatedUser()));
    }

    /** POST /api/v1/student/ai/lessons/{uuid}/quiz */
    public function generateLessonQuiz(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'quiz_count' => ['nullable', 'integer', 'min:3', 'max:10'],
        ]);

        try {
            $result = $this->aiService->startLessonQuiz(
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
            $result,
            __('messages.ai.quiz_generation_started'),
            Response::HTTP_ACCEPTED
        );
    }

    /** GET /api/v1/student/ai/weak-areas/latest */
    public function latestWeakAreaDiagnosis(): JsonResponse
    {
        return $this->successResponse($this->aiService->getLatestCoachReport($this->authenticatedUser()));
    }

    /** POST /api/v1/student/ai/weak-areas/diagnose */
    public function diagnoseWeakAreas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mistake_ids' => ['nullable', 'array', 'max:20'],
            'mistake_ids.*' => ['uuid'],
            'include_quiz' => ['nullable', 'boolean'],
            'quiz_count' => ['nullable', 'integer', 'min:3', 'max:10'],
        ]);

        try {
            $result = $this->aiService->startWeakAreaDiagnosis(
                $this->authenticatedUser(),
                (array) ($validated['mistake_ids'] ?? []),
                array_key_exists('include_quiz', $validated) ? (bool) $validated['include_quiz'] : true,
                (int) ($validated['quiz_count'] ?? 5)
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage() ?: __('messages.ai.service_unavailable'),
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        return $this->successResponse(
            $result,
            __('messages.ai.student_coach_started'),
            Response::HTTP_ACCEPTED
        );
    }

    /** GET /api/v1/student/ai/jobs/{uuid} */
    public function jobStatus(string $uuid): JsonResponse
    {
        return $this->successResponse($this->aiService->getJobStatus($this->authenticatedUser(), $uuid));
    }
}
