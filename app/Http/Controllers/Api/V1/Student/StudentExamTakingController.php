<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\SubmitExamRequest;
use App\Http\Resources\V1\Student\StudentExamSubmissionResource;
use App\Services\V1\Student\StudentExamTakingService;
use Illuminate\Http\JsonResponse;

class StudentExamTakingController extends BaseApiController
{
    public function __construct(
        protected StudentExamTakingService $examTakingService
    ) {}

    /**
     * GET /api/v1/student/exams
     * List accessible exams for the student with submission status.
     */
    public function index(): JsonResponse
    {
        $exams = $this->examTakingService->listExams($this->authenticatedUser());

        return $this->successResponse($exams);
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
