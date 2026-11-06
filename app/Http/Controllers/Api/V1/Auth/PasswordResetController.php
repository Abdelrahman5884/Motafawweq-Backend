<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\V1\Auth\ResetPasswordRequest;
use App\Services\V1\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends BaseApiController
{
    public function __construct(
        protected PasswordResetService $passwordResetService
    ) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->requestReset($request->validated('email'), $request->ip());

        return $this->successResponse(null, __('messages.password_forgot_sent'));
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->reset(
            email: $request->validated('email'),
            code: $request->validated('code'),
            newPassword: $request->validated('password'),
            ip: $request->ip()
        );

        return $this->successResponse(null, __('messages.password_reset_success'));
    }
}
