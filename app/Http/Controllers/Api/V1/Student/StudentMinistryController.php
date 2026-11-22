<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Student\StudentMinistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentMinistryController extends BaseApiController
{
    public function __construct(
        protected StudentMinistryService $ministryService
    ) {}

    /**
     * GET /api/v1/student/ministry/curricula
     */
    public function curricula(Request $request): JsonResponse
    {
        $grade = $request->query('grade');
        $data = $this->ministryService->listCurricula(
            $this->authenticatedUser(),
            is_string($grade) ? $grade : null
        );

        return $this->successResponse($data);
    }

    /**
     * POST /api/v1/student/ministry/curricula/{code}/units/{unitIndex}/toggle
     */
    public function toggleUnit(string $code, int $unitIndex): JsonResponse
    {
        $result = $this->ministryService->toggleUnitCompletion(
            $this->authenticatedUser(),
            $code,
            $unitIndex
        );

        $message = $result['isCompleted']
            ? 'تم تسجيل إنجاز الوحدة بنجاح.'
            : 'تم إلغاء تحديد الوحدة.';

        return $this->successResponse($result, $message);
    }

    /**
     * GET /api/v1/student/ministry/exams
     */
    public function exams(Request $request): JsonResponse
    {
        $grade = $request->query('grade');
        $data = $this->ministryService->listExams(
            $this->authenticatedUser(),
            is_string($grade) ? $grade : null
        );

        return $this->successResponse($data);
    }

    /**
     * POST /api/v1/student/ministry/exams/{code}/start
     */
    public function startExam(Request $request, string $code): JsonResponse
    {
        $session = $this->ministryService->startExam(
            $this->authenticatedUser(),
            $code,
            $request->boolean('retake', false)
        );

        return $this->successResponse($session);
    }

    /**
     * POST /api/v1/student/ministry/exams/{code}/submit
     */
    public function submitExam(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'tabSwitchesCount' => ['nullable', 'integer', 'min:0', 'max:500'],
            'tab_switches_count' => ['nullable', 'integer', 'min:0', 'max:500'],
            'answers' => ['required', 'array'],
            'answers.*.questionUuid' => ['nullable', 'string', 'max:80'],
            'answers.*.question_uuid' => ['nullable', 'string', 'max:80'],
            'answers.*.questionId' => ['nullable', 'string', 'max:80'],
            'answers.*.selectedIndex' => ['nullable', 'integer', 'min:0', 'max:10'],
            'answers.*.selected_index' => ['nullable', 'integer', 'min:0', 'max:10'],
            'answers.*.selectedBool' => ['nullable', 'boolean'],
            'answers.*.selected_bool' => ['nullable', 'boolean'],
        ]);

        $result = $this->ministryService->submitExam(
            $this->authenticatedUser(),
            $code,
            $validated
        );

        return $this->createdResponse($result, __('messages.exam_submitted_successfully'));
    }

    /**
     * GET /api/v1/student/ministry/exams/{code}/result
     */
    public function examResult(string $code): JsonResponse
    {
        $result = $this->ministryService->getExamResult(
            $this->authenticatedUser(),
            $code
        );

        return $this->successResponse($result);
    }
}
