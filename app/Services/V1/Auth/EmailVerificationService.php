<?php

namespace App\Services\V1\Auth;

use App\Enums\UserStatus;
use App\Enums\VerificationCodeType;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailOtpNotification;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Validation\ValidationException;

class EmailVerificationService extends BaseService
{
    public function __construct(
        protected OtpService $otpService,
        protected UserRepositoryInterface $userRepository
    ) {}

    public function resend(User $user, ?string $ip): void
    {
        $this->ensureNotVerified($user);

        $code = $this->otpService->generate($user, VerificationCodeType::EMAIL_VERIFICATION, $ip);
        $this->safeNotify($user, new VerifyEmailOtpNotification($code, (int) config('auth_security.otp.ttl_minutes')));

        $this->logSecurityEvent('EMAIL_VERIFICATION_RESENT', ['user_id' => $user->id]);
    }

    public function verify(User $user, string $code): User
    {
        $this->ensureNotVerified($user);

        $this->otpService->verify($user, VerificationCodeType::EMAIL_VERIFICATION, $code);
        $this->userRepository->forceUpdate($user, [
            'email_verified_at' => now(),
            'status'            => UserStatus::ACTIVE->value,
        ]);

        $this->logSecurityEvent('EMAIL_VERIFIED', ['user_id' => $user->id]);

        return $user->fresh();
    }

    protected function ensureNotVerified(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => [__('messages.email_already_verified')]]);
        }
    }
}

