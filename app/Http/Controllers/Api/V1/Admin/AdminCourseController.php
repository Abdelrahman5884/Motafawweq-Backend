<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Admin\AdminCourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCourseController extends BaseApiController
{
    public function __construct(
        protected AdminCourseService $courseService
    ) {}

    /**
     * GET /api/v1/admin/courses
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->courseService->listCourses([
            'search' => $request->query('search'),
            'stage' => $request->query('stage'),
            'status' => $request->query('status'),
            'featured' => $request->query('featured'),
            'per_page' => $request->query('per_page', 20),
        ]);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.courses_fetched'),
            200,
            array_merge($result['meta'], ['summary' => $result['summary']])
        );
    }

    /**
     * GET /api/v1/admin/courses/{uuid}
     */
    public function show(string $uuid): JsonResponse
    {
        $course = $this->courseService->getCourse($uuid);

        return $this->successResponse($course, __('messages.admin.course_fetched'));
    }

    /**
     * PATCH /api/v1/admin/courses/{uuid}/toggle-publish
     */
    public function togglePublish(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $course = $this->courseService->togglePublish(
            $this->authenticatedUser(),
            $uuid,
            $validated['notes'] ?? null
        );

        return $this->successResponse($course, __('messages.admin.course_publish_toggled'));
    }

    /**
     * PATCH /api/v1/admin/courses/{uuid}/toggle-featured
     */
    public function toggleFeatured(string $uuid): JsonResponse
    {
        $course = $this->courseService->toggleFeatured($this->authenticatedUser(), $uuid);

        return $this->successResponse($course, __('messages.admin.course_featured_toggled'));
    }

    /**
     * DELETE /api/v1/admin/courses/{uuid}
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->courseService->deleteCourse(
            $this->authenticatedUser(),
            $uuid,
            $validated['reason'] ?? null
        );

        return $this->successResponse(null, __('messages.admin.course_deleted'));
    }
}
