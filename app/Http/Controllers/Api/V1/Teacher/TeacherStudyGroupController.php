<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\StoreGroupAnnouncementRequest;
use App\Http\Requests\V1\Teacher\StoreStudyGroupRequest;
use App\Http\Requests\V1\Teacher\UpdateStudyGroupRequest;
use App\Http\Resources\V1\Teacher\StudyGroupResource;
use App\Models\GroupAnnouncement;
use App\Services\V1\Teacher\TeacherStudyGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherStudyGroupController extends BaseApiController
{
    public function __construct(
        protected TeacherStudyGroupService $groupService
    ) {}

    /**
     * GET /api/v1/teacher/groups
     * List all study groups for the authenticated teacher.
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = $this->authenticatedUser();
        $groups = $this->groupService->listGroups(
            $teacher,
            $request->only(['search', 'q', 'center_name', 'status'])
        );

        return $this->successResponse(
            StudyGroupResource::collection($groups),
            __('messages.groups_fetched'),
            meta: $this->groupService->getGroupsMeta($teacher, $groups->count())
        );
    }

    /**
     * POST /api/v1/teacher/groups
     * Create a new study group with schedule slots and join code.
     */
    public function store(StoreStudyGroupRequest $request): JsonResponse
    {
        $group = $this->groupService->createGroup(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse(
            new StudyGroupResource($group),
            __('messages.group_created')
        );
    }

    /**
     * GET /api/v1/teacher/groups/{uuid}
     * Retrieve a specific study group.
     */
    public function show(string $uuid): JsonResponse
    {
        $group = $this->groupService->getGroup(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new StudyGroupResource($group),
            __('messages.group_fetched')
        );
    }

    /**
     * PUT /api/v1/teacher/groups/{uuid}
     * Update a study group and synchronize schedule slots.
     */
    public function update(UpdateStudyGroupRequest $request, string $uuid): JsonResponse
    {
        $group = $this->groupService->updateGroup(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse(
            new StudyGroupResource($group),
            __('messages.group_updated')
        );
    }

    /**
     * DELETE /api/v1/teacher/groups/{uuid}
     * Delete a study group (soft delete).
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->groupService->deleteGroup(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            null,
            __('messages.group_deleted')
        );
    }

    /**
     * POST /api/v1/teacher/groups/{uuid}/regenerate-code
     * Invalidate old join code and generate a fresh code (Anti-leakage).
     */
    public function regenerateCode(string $uuid): JsonResponse
    {
        $group = $this->groupService->regenerateJoinCode(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new StudyGroupResource($group),
            __('messages.group_code_regenerated')
        );
    }

    /**
     * GET /api/v1/teacher/groups/{uuid}/announcements
     * List recent announcements sent to a study group.
     */
    public function announcements(string $uuid): JsonResponse
    {
        $announcements = $this->groupService->listAnnouncements(
            $this->authenticatedUser(),
            $uuid
        );

        $data = $announcements->map(fn (GroupAnnouncement $item) => [
            'id' => $item->uuid,
            'type' => $item->type,
            'message' => $item->message,
            'recipientsCount' => (int) $item->recipients_count,
            'createdAt' => $item->created_at?->toIso8601String(),
        ])->values();

        return $this->successResponse(
            $data,
            __('messages.group_announcements_fetched'),
            meta: ['total' => $data->count()]
        );
    }

    /**
     * POST /api/v1/teacher/groups/{uuid}/announcements
     * Dispatch and persist an announcement/alert for a study group.
     */
    public function storeAnnouncement(StoreGroupAnnouncementRequest $request, string $uuid): JsonResponse
    {
        $announcement = $this->groupService->sendAnnouncement(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->createdResponse(
            [
                'id' => $announcement->uuid,
                'type' => $announcement->type,
                'message' => $announcement->message,
                'recipientsCount' => (int) $announcement->recipients_count,
                'createdAt' => $announcement->created_at?->toIso8601String(),
            ],
            __('messages.group_announcement_sent')
        );
    }
}
