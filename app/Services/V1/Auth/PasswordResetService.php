<?php

namespace App\Services\V1\Auth;

use App\Enums\UserStatus;
use App\Enums\VerificationCodeType;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordOtpNotification;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;

/**
 * OTP-based password reset that never reveals whether an email is registered.
 */
class PasswordResetService extends BaseService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected OtpService $otpService,
        protected TokenService $tokenService
    ) {}

    /**
     * Always succeeds from the caller's point of view (anti user-enumeration).
     */
    public function requestReset(string $email, ?string $ip): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user || $user->status === UserStatus::SUSPENDED) {
            $this->logSecurityEvent('PASSWORD_RESET_REQUEST_IGNORED', ['email' => strtolower($email)], 'warning');
            return;
        }

        try {
            $code = $this->otpService->generate($user, VerificationCodeType::PASSWORD_RESET, $ip);
        } catch (ThrottleRequestsException) {
            // Silently swallow: revealing the cooldown would reveal the account exists
            return;
        }

        $this->safeNotify($user, new ResetPasswordOtpNotification($code, (int) config('auth_security.otp.ttl_minutes')));
        $this->logSecurityEvent('PASSWORD_RESET_REQUESTED', ['user_id' => $user->id]);
    }

    /**
     * Verify the OTP and set the new password. Revokes every session and unlocks the account.
     *
     * @throws ValidationException
     */
    public function reset(string $email, string $code, string $newPassword, ?string $ip): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user || $user->status === UserStatus::SUSPENDED) {
            throw ValidationException::withMessages(['code' => [__('messages.otp_invalid')]]);
        }

        $this->otpService->verify($user, VerificationCodeType::PASSWORD_RESET, $code);

        $this->transaction(function () use ($user, $newPassword) {
            $this->userRepository->forceUpdate($user, [
                'password'              => $newPassword,
                'password_changed_at'   => now(),
                'failed_login_attempts' => 0,
                'locked_until'          => null,
                // Receiving the code by email proves ownership of the mailbox
                'email_verified_at'     => $user->email_verified_at ?? now(),
            ]);

            $this->tokenService->revokeAll($user);
        });

        $this->safeNotify($user, new PasswordChangedNotification($ip));
        $this->logSecurityEvent('PASSWORD_RESET_COMPLETED', ['user_id' => $user->id]);
    }
}

