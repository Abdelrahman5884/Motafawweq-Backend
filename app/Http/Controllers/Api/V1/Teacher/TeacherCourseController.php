<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\StoreTeacherCourseRequest;
use App\Http\Requests\V1\Teacher\UpdateTeacherCourseRequest;
use App\Http\Resources\V1\Teacher\TeacherCourseResource;
use App\Services\V1\Teacher\TeacherCourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherCourseController extends BaseApiController
{
    public function __construct(
        protected TeacherCourseService $courseService
    ) {}

    /**
     * GET /api/v1/teacher/courses
     * List all courses owned by the authenticated teacher with optional search & filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $courses = $this->courseService->listCourses(
            $this->authenticatedUser(),
            $request->only(['search', 'q', 'stage', 'stage_id', 'status'])
        );

        return $this->successResponse(
            TeacherCourseResource::collection($courses),
            __('messages.courses_fetched'),
            meta: ['total' => $courses->count()]
        );
    }

    /**
     * GET /api/v1/teacher/courses/{uuid}
     * Fetch a single course owned by the authenticated teacher.
     */
    public function show(string $uuid): JsonResponse
    {
        $course = $this->courseService->getCourse(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherCourseResource($course),
            __('messages.course_fetched')
        );
    }

    /**
     * POST /api/v1/teacher/courses
     * Create a new course (protected by teacher.quota:courses middleware).
     */
    public function store(StoreTeacherCourseRequest $request): JsonResponse
    {
        $course = $this->courseService->createCourse(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse(
            new TeacherCourseResource($course),
            __('messages.course_created')
        );
    }

    /**
     * PUT /api/v1/teacher/courses/{uuid}
     * Update an existing course owned by the authenticated teacher.
     */
    public function update(UpdateTeacherCourseRequest $request, string $uuid): JsonResponse
    {
        $course = $this->courseService->updateCourse(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse(
            new TeacherCourseResource($course),
            __('messages.course_updated')
        );
    }

    /**
     * DELETE /api/v1/teacher/courses/{uuid}
     * Soft-delete a course owned by the authenticated teacher.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->courseService->deleteCourse(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            null,
            __('messages.course_deleted')
        );
    }
}

