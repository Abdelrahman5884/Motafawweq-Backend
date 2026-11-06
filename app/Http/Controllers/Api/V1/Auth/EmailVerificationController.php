<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Auth\VerifyEmailRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\V1\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends BaseApiController
{
    public function __construct(
        protected EmailVerificationService $verificationService
    ) {}

    /**
     * POST /auth/email/verify
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $user = $this->verificationService->verify($request->user(), $request->validated('code'));

        return $this->successResponse(new UserResource($user), 'تم تأكيد البريد الإلكتروني بنجاح');
    }

    /**
     * POST /auth/email/resend
     */
    public function resend(Request $request): JsonResponse
    {
        $this->verificationService->resend($request->user(), $request->ip());

        return $this->successResponse(null, 'تم إرسال رمز تأكيد جديد إلى بريدك الإلكتروني');
    }
}

