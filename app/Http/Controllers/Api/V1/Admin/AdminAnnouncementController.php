<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Admin\SaveAdminAnnouncementRequest;
use App\Services\V1\Admin\AdminAnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAnnouncementController extends BaseApiController
{
    public function __construct(
        protected AdminAnnouncementService $announcementService
    ) {}

    /**
     * GET /api/v1/admin/announcements
     */
    public function index(Request $request): JsonResponse
    {
        $items = $this->announcementService->listAll([
            'audience' => $request->query('audience'),
            'priority' => $request->query('priority'),
            'active' => $request->query('active'),
        ]);

        return $this->successResponse($items, __('messages.admin.announcements_fetched'));
    }

    /**
     * POST /api/v1/admin/announcements
     */
    public function store(SaveAdminAnnouncementRequest $request): JsonResponse
    {
        $item = $this->announcementService->create($this->authenticatedUser(), $request->validated());

        return $this->createdResponse($item, __('messages.admin.announcement_created'));
    }

    /**
     * PUT /api/v1/admin/announcements/{uuid}
     */
    public function update(SaveAdminAnnouncementRequest $request, string $uuid): JsonResponse
    {
        $item = $this->announcementService->update($this->authenticatedUser(), $uuid, $request->validated());

        return $this->successResponse($item, __('messages.admin.announcement_updated'));
    }

    /**
     * PATCH /api/v1/admin/announcements/{uuid}/toggle-active
     */
    public function toggleActive(string $uuid): JsonResponse
    {
        $item = $this->announcementService->toggleActive($this->authenticatedUser(), $uuid);

        return $this->successResponse($item, __('messages.admin.announcement_toggled'));
    }

    /**
     * DELETE /api/v1/admin/announcements/{uuid}
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->announcementService->delete($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.admin.announcement_deleted'));
    }
}
