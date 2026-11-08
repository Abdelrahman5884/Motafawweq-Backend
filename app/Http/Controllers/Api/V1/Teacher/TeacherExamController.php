<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\StoreExamRequest;
use App\Http\Requests\V1\Teacher\UpdateExamRequest;
use App\Http\Resources\V1\Teacher\TeacherExamResource;
use App\Services\V1\Teacher\TeacherExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherExamController extends BaseApiController
{
    public function __construct(
        protected TeacherExamService $examService
    ) {}

    /**
     * GET /api/v1/teacher/exams
     * List all exams for the teacher with optional search and status filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $exams = $this->examService->listExams(
            $this->authenticatedUser(),
            $request->only(['search', 'q', 'status'])
        );

        return $this->successResponse(
            TeacherExamResource::collection($exams),
            __('messages.exams_fetched'),
            meta: ['total' => $exams->count()]
        );
    }

    /**
     * POST /api/v1/teacher/exams
     * Create a new exam with its questions matrix.
     */
    public function store(StoreExamRequest $request): JsonResponse
    {
        $exam = $this->examService->createExam(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse(
            new TeacherExamResource($exam),
            __('messages.exam_created')
        );
    }

    /**
     * GET /api/v1/teacher/exams/{uuid}
     * Retrieve exam details with all questions and answers.
     */
    public function show(string $uuid): JsonResponse
    {
        $exam = $this->examService->getExam(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherExamResource($exam),
            __('messages.exam_fetched')
        );
    }

    /**
     * PUT /api/v1/teacher/exams/{uuid}
     * Update exam settings and optionally synchronize questions.
     */
    public function update(UpdateExamRequest $request, string $uuid): JsonResponse
    {
        $exam = $this->examService->updateExam(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse(
            new TeacherExamResource($exam),
            __('messages.exam_updated')
        );
    }

    /**
     * PATCH /api/v1/teacher/exams/{uuid}/toggle-status
     * Toggle exam availability status (active <-> closed).
     */
    public function toggleStatus(string $uuid): JsonResponse
    {
        $exam = $this->examService->toggleStatus(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherExamResource($exam),
            __('messages.exam_status_toggled')
        );
    }

    /**
     * PATCH /api/v1/teacher/exams/{uuid}/toggle-results
     * Toggle whether exam results and model answers are visible to students.
     */
    public function toggleResults(string $uuid): JsonResponse
    {
        $exam = $this->examService->toggleResults(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherExamResource($exam),
            __('messages.exam_results_toggled')
        );
    }

    /**
     * POST /api/v1/teacher/exams/{uuid}/duplicate
     * Duplicate an existing exam with all its questions.
     */
    public function duplicate(string $uuid): JsonResponse
    {
        $exam = $this->examService->duplicateExam(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->createdResponse(
            new TeacherExamResource($exam),
            __('messages.exam_duplicated')
        );
    }

    /**
     * DELETE /api/v1/teacher/exams/{uuid}
     * Soft delete an exam.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->examService->deleteExam(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            null,
            __('messages.exam_deleted')
        );
    }
}

