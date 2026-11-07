<?php

namespace App\Services\V1\Auth;

use App\Enums\UserStatus;
use App\Enums\VerificationCodeType;
use App\Exceptions\AuthSecurityException;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordOtpNotification;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * OTP-based password reset service.
 */
class PasswordResetService extends BaseService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected OtpService $otpService,
        protected TokenService $tokenService
    ) {}

    /**
     * Validate registered email, generate OTP, and send password reset email.
     *
     * @throws ValidationException|AuthSecurityException
     */
    public function requestReset(string $email, ?string $ip): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => [__('messages.email_not_found')],
            ]);
        }

        if ($user->status === UserStatus::SUSPENDED) {
            throw AuthSecurityException::accountSuspended();
        }

        $code = $this->otpService->generate($user, VerificationCodeType::PASSWORD_RESET, $ip);

        try {
            $user->notify(new ResetPasswordOtpNotification($code, (int) config('auth_security.otp.ttl_minutes')));
        } catch (Throwable $e) {
            Log::error('[PASSWORD-RESET-MAIL-FAILED]', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'error'   => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => [__('messages.mail_delivery_failed')],
            ]);
        }

        $this->logSecurityEvent('PASSWORD_RESET_REQUESTED', ['user_id' => $user->id]);
    }

    /**
     * Check that a password-reset OTP is valid without consuming it yet (Step 2).
     *
     * @throws ValidationException
     */
    public function verifyResetOtp(string $email, string $code): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user || $user->status === UserStatus::SUSPENDED) {
            throw ValidationException::withMessages(['code' => [__('messages.otp_invalid')]]);
        }

        $this->otpService->check($user, VerificationCodeType::PASSWORD_RESET, $code);
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

