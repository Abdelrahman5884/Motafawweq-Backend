<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\StoreTeacherPayoutAccountRequest;
use App\Http\Resources\V1\Teacher\TeacherPayoutAccountResource;
use App\Services\V1\Teacher\TeacherPayoutAccountService;
use Illuminate\Http\JsonResponse;

class TeacherPayoutAccountController extends BaseApiController
{
    public function __construct(
        protected TeacherPayoutAccountService $payoutService
    ) {}

    /**
     * GET /api/v1/teacher/payout-accounts
     * List all encrypted payout accounts for the authenticated teacher.
     */
    public function index(): JsonResponse
    {
        $accounts = $this->payoutService->listAccounts($this->authenticatedUser());

        return $this->successResponse(
            TeacherPayoutAccountResource::collection($accounts),
            __('messages.payout_accounts_fetched')
        );
    }

    /**
     * POST /api/v1/teacher/payout-accounts
     * Add a new encrypted receiving account (InstaPay, Vodafone Cash, Bank/CIB, Card).
     */
    public function store(StoreTeacherPayoutAccountRequest $request): JsonResponse
    {
        $account = $this->payoutService->createAccount(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse(
            new TeacherPayoutAccountResource($account),
            __('messages.payout_account_created')
        );
    }

    /**
     * PATCH /api/v1/teacher/payout-accounts/{uuid}/toggle-active
     * Enable or disable a payout channel.
     */
    public function toggleActive(string $uuid): JsonResponse
    {
        $account = $this->payoutService->toggleActive(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherPayoutAccountResource($account),
            __('messages.payout_account_updated')
        );
    }

    /**
     * PATCH /api/v1/teacher/payout-accounts/{uuid}/set-default
     * Set a payout account as the default receiving method.
     */
    public function setDefault(string $uuid): JsonResponse
    {
        $account = $this->payoutService->setDefault(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherPayoutAccountResource($account),
            __('messages.payout_account_default_updated')
        );
    }

    /**
     * DELETE /api/v1/teacher/payout-accounts/{uuid}
     * Remove a payout account and promote a fallback default if needed.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->payoutService->deleteAccount(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            null,
            __('messages.payout_account_deleted')
        );
    }
}

