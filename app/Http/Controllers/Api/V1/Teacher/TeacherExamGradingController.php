<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\GradeEssayRequest;
use App\Http\Resources\V1\Teacher\TeacherExamSubmissionDetailResource;
use App\Http\Resources\V1\Teacher\TeacherExamSubmissionResource;
use App\Services\V1\Teacher\TeacherExamGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherExamGradingController extends BaseApiController
{
    public function __construct(
        protected TeacherExamGradingService $gradingService
    ) {}

    /**
     * GET /api/v1/teacher/exams/{uuid}/submissions
     * List all student submissions and performance metrics for the specified exam.
     */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $result = $this->gradingService->listSubmissions(
            $this->authenticatedUser(),
            $uuid,
            $request->only(['search', 'q', 'status', 'is_passed'])
        );

        return $this->successResponse(
            TeacherExamSubmissionResource::collection($result['submissions']),
            __('messages.exam_submissions_fetched'),
            meta: [
                'total'            => $result['submissions']->count(),
                'avgScore'         => (float) $result['exam']->avg_score,
                'passRate'         => (float) $result['exam']->pass_rate,
                'topScoreCount'    => (int) $result['exam']->top_score_count,
                'submissionsCount' => (int) $result['exam']->submissions_count,
            ]
        );
    }

    /**
     * GET /api/v1/teacher/exams/{uuid}/submissions/{submissionUuid}
     * Retrieve full submission details with student choices, answers, and model answers.
     */
    public function show(string $uuid, string $submissionUuid): JsonResponse
    {
        $submission = $this->gradingService->getSubmission(
            $this->authenticatedUser(),
            $uuid,
            $submissionUuid
        );

        return $this->successResponse(
            new TeacherExamSubmissionDetailResource($submission),
            __('messages.exam_submission_fetched')
        );
    }

    /**
     * PATCH /api/v1/teacher/exams/{uuid}/submissions/{submissionUuid}/grade-essay
     * Grade essay questions, award points, record feedback, and update total score & pass rate.
     */
    public function gradeEssay(GradeEssayRequest $request, string $uuid, string $submissionUuid): JsonResponse
    {
        $submission = $this->gradingService->gradeEssaySubmission(
            $this->authenticatedUser(),
            $uuid,
            $submissionUuid,
            $request->validated()
        );

        return $this->successResponse(
            new TeacherExamSubmissionDetailResource($submission),
            __('messages.exam_submission_graded')
        );
    }
}

