<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Admin\SaveAdminChampionRequest;
use App\Services\V1\Admin\AdminChampionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminChampionController extends BaseApiController
{
    public function __construct(
        protected AdminChampionService $championService
    ) {}

    /**
     * GET /api/v1/admin/champions
     * Supports server-side pagination, grade filtering, visibility filtering, source filtering, and search.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'grade' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'pinned' => ['nullable', 'string', 'in:all,pinned,hidden'],
            'source' => ['nullable', 'string', 'in:all,manual,republic_league'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:3', 'max:50'],
        ]);

        $result = $this->championService->listPaginated($validated);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.champions_fetched'),
            200,
            $result['meta']
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
     * POST /api/v1/admin/champions/approve-nominee
     * Approve & publish an auto-detected Republic League top performer (with optional edited text & custom photo).
     */
    public function approveNominee(SaveAdminChampionRequest $request): JsonResponse
    {
        $champion = $this->championService->approveNominee($this->authenticatedUser(), $request->validated());

        return $this->successResponse($champion, __('messages.admin.champion_approved'));
    }

    /**
     * POST /api/v1/admin/champions/upload-photo
     * Hardened portrait photo upload for homepage slider champions.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ]);

        $result = $this->championService->uploadPhoto(
            $this->authenticatedUser(),
            $request->file('photo')
        );

        return $this->createdResponse($result, __('messages.admin.champion_photo_uploaded'));
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
