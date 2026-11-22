<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\StoreCenterBranchRequest;
use App\Http\Requests\V1\Center\UpdateCenterBranchRequest;
use App\Services\V1\Center\CenterBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CenterBranchController extends BaseApiController
{
    public function __construct(
        protected CenterBranchService $branchService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $branches = $this->branchService->listBranches(
            $this->authenticatedUser(),
            $request->only(['search', 'is_active'])
        );

        return $this->successResponse(
            $branches,
            __('messages.center.branches_fetched'),
            meta: ['total' => count($branches)]
        );
    }

    public function store(StoreCenterBranchRequest $request): JsonResponse
    {
        $branch = $this->branchService->createBranch(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse($branch, __('messages.center.branch_created'));
    }

    public function show(string $uuid): JsonResponse
    {
        $branch = $this->branchService->findOwnedBranch($this->authenticatedUser(), $uuid);

        return $this->successResponse(
            $this->branchService->formatBranch($branch),
            __('messages.center.branch_fetched')
        );
    }

    public function update(UpdateCenterBranchRequest $request, string $uuid): JsonResponse
    {
        $branch = $this->branchService->updateBranch(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse($branch, __('messages.center.branch_updated'));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->branchService->deleteBranch($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.center.branch_deleted'));
    }

    public function exportCsv(): Response
    {
        $csv = $this->branchService->exportBranchesCsv($this->authenticatedUser());

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="center-branches.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
