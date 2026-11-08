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
     * POST /api/v1/exams/{uuid}/submit
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
}

