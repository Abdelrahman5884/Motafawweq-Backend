<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\SubmitExamRequest;
use App\Http\Resources\V1\Student\StudentExamSubmissionResource;
use App\Services\V1\Student\StudentExamTakingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentExamTakingController extends BaseApiController
{
    public function __construct(
        protected StudentExamTakingService $examTakingService
    ) {}

    /**
     * GET /api/v1/student/exams
     * List accessible exams for the student (scoped to student's grade by default;
     * searches across all grades when `?search=` is provided).
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $exams = $this->examTakingService->listExams(
            $this->authenticatedUser(),
            is_string($search) ? $search : null
        );

        return $this->successResponse($exams);
    }

    /**
     * GET /api/v1/student/exams/{uuid}/result
     * Retrieve the authenticated student's submitted/graded exam result and review breakdown.
     */
    public function result(string $uuid): JsonResponse
    {
        $submission = $this->examTakingService->getExamResult(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(new StudentExamSubmissionResource($submission));
    }

    /**
     * POST /api/v1/student/exams/{uuid}/start
     * Start or resume a server-timed exam session.
     */
    public function start(string $uuid): JsonResponse
    {
        $session = $this->examTakingService->startExam(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse($session);
    }

    /**
     * POST /api/v1/student/exams/{uuid}/submit (and legacy /api/v1/exams/{uuid}/submit)
     * Submit answers for an exam, auto-grade objective questions, and return submission summary.
     */
    public function submit(SubmitExamRequest $request, string $uuid): JsonResponse
    {
        $submission = $this->examTakingService->submitExam(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->createdResponse(
            new StudentExamSubmissionResource($submission),
            __('messages.exam_submitted_successfully')
        );
    }

    /**
     * GET /api/v1/student/mistake-bank
     * Retrieve objective questions answered wrong across graded exams for revision.
     */
    public function mistakeBank(): JsonResponse
    {
        $mistakes = $this->examTakingService->getMistakeBank($this->authenticatedUser());

        return $this->successResponse($mistakes);
    }
}
