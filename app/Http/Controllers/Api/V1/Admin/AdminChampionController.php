<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Admin\SaveAdminChampionRequest;
use App\Services\V1\Admin\AdminChampionService;
use Illuminate\Http\JsonResponse;

class AdminChampionController extends BaseApiController
{
    public function __construct(
        protected AdminChampionService $championService
    ) {}

    /**
     * GET /api/v1/admin/champions
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->championService->listAll(),
            __('messages.admin.champions_fetched')
        );
    }

    /**
     * POST /api/v1/admin/champions
     */
    public function store(SaveAdminChampionRequest $request): JsonResponse
    {
        $champion = $this->championService->create($this->authenticatedUser(), $request->validated());

        return $this->createdResponse($champion, __('messages.admin.champion_created'));
    }

    /**
     * PUT /api/v1/admin/champions/{uuid}
     */
    public function update(SaveAdminChampionRequest $request, string $uuid): JsonResponse
    {
        $champion = $this->championService->update($this->authenticatedUser(), $uuid, $request->validated());

        return $this->successResponse($champion, __('messages.admin.champion_updated'));
    }

    /**
     * PATCH /api/v1/admin/champions/{uuid}/toggle-pin
     */
    public function togglePin(string $uuid): JsonResponse
    {
        $champion = $this->championService->togglePin($this->authenticatedUser(), $uuid);

        return $this->successResponse($champion, __('messages.admin.champion_pin_toggled'));
    }

    /**
     * DELETE /api/v1/admin/champions/{uuid}
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->championService->delete($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.admin.champion_deleted'));
    }
}
