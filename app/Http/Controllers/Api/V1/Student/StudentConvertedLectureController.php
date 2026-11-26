<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Student\StudentConvertedLectureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** DELETE /api/v1/student/converted-lectures/{uuid} */
    public function destroy(string $uuid): JsonResponse
    {
        $this->service->delete($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, 'تم حذف المحاضرة من أرشيفك.');
    }
}
