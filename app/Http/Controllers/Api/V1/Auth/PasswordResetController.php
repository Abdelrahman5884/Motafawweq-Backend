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

    /**
     * POST /auth/password/forgot
     * Always returns the same response whether the email exists or not.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->requestReset($request->validated('email'), $request->ip());

        return $this->successResponse(
            null,
            'إذا كان البريد الإلكتروني مسجلاً لدينا، فسيصلك رمز إعادة تعيين كلمة المرور خلال لحظات.'
        );
    }

    /**
     * POST /auth/password/reset
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->reset(
            email: $request->validated('email'),
            code: $request->validated('code'),
            newPassword: $request->validated('password'),
            ip: $request->ip()
        );

        return $this->successResponse(null, 'تم تعيين كلمة المرور الجديدة بنجاح. يرجى تسجيل الدخول.');
    }
}

