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

    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $user = $this->verificationService->verify($request->user(), $request->validated('code'));

        return $this->successResponse(new UserResource($user), __('messages.email_verified_success'));
    }

    public function resend(Request $request): JsonResponse
    {
        $this->verificationService->resend($request->user(), $request->ip());

        return $this->successResponse(null, __('messages.email_otp_resent'));
    }
}
