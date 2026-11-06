<?php

namespace App\Services\V1\Auth;

use App\Enums\LoginStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationCodeType;
use App\Exceptions\AuthSecurityException;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Notifications\Auth\NewDeviceLoginNotification;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\VerifyEmailOtpNotification;
use App\Repositories\Contracts\LoginHistoryRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService extends BaseService
{
    /** Lazily computed bcrypt hash used to equalise timing when a user does not exist. */
    private static ?string $dummyHash = null;

    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected LoginHistoryRepositoryInterface $loginHistory,
        protected TokenService $tokenService,
        protected OtpService $otpService
    ) {}

    /**
     * Register a new user, send an email-verification OTP and open a session.
     *
     * @return array{user: User, tokens: array}
     */
    public function register(array $data, ?string $ip, ?string $userAgent): array
    {
        $role = UserRole::from($data['role'] ?? UserRole::STUDENT->value);

        // Defence in depth: request validation already restricts roles, re-check here
        if (!in_array($role->value, config('auth_security.self_registration_roles'), true)) {
            throw ValidationException::withMessages(['role' => [__('messages.role_not_allowed')]]);
        }

        $status = in_array($role->value, config('auth_security.roles_requiring_approval'), true)
            ? UserStatus::PENDING
            : UserStatus::ACTIVE;

        $linkedStudent = null;
        if ($role === UserRole::PARENT) {
            $linkedStudent = $this->userRepository->findStudentByCode((string) ($data['student_code'] ?? ''));

            if (!$linkedStudent) {
                throw ValidationException::withMessages([
                    'student_code' => [__('messages.invalid_student_code')],
                ]);
            }
        }

        [$user, $tokens] = $this->transaction(function () use ($data, $role, $status, $linkedStudent, $ip, $userAgent) {
            $studentCode = $role === UserRole::STUDENT
                ? $this->userRepository->generateUniqueStudentCode()
                : null;

            /** @var User $user */
            $user = $this->userRepository->forceCreate([
                'name'                => $data['name'],
                'email'               => strtolower(trim($data['email'])),
                'password'            => $data['password'], // hashed by the model cast
                'phone'               => $data['phone'] ?? null,
                'role'                => $role->value,
                'student_code'        => $studentCode,
                'status'              => $status->value,
                'password_changed_at' => now(),
            ]);

            if ($linkedStudent) {
                $user->children()->syncWithoutDetaching([$linkedStudent->id]);
            }

            $tokens = $this->tokenService->issuePair($user, $ip, $userAgent);
            $this->loginHistory->record($user, $user->email, LoginStatus::SUCCESS, $ip, $userAgent);

            return [$user, $tokens];
        });

        // Outside the transaction: a mail failure must never roll back the account
        $code = $this->otpService->generate($user, VerificationCodeType::EMAIL_VERIFICATION, $ip);
        $this->safeNotify($user, new VerifyEmailOtpNotification($code, (int) config('auth_security.otp.ttl_minutes')));

        $this->logSecurityEvent('USER_REGISTERED', [
            'user_id' => $user->id,
            'role'    => $role->value,
            'status'  => $status->value,
        ]);

        return ['user' => $user->fresh(), 'tokens' => $tokens];
    }

    /**
     * Authenticate credentials with lockout, timing-attack mitigation and audit trail.
     *
     * @return array{user: User, tokens: array}
     * @throws AuthSecurityException
     */
    public function login(string $email, string $password, ?string $ip, ?string $userAgent): array
    {
        $email = strtolower(trim($email));
        $user = $this->userRepository->findByEmail($email);

        // 1) Unknown email: burn the same CPU time as a real check, answer generically
        if (!$user) {
            Hash::check($password, self::dummyHash());
            $this->loginHistory->record(null, $email, LoginStatus::FAILED, $ip, $userAgent);
            $this->logSecurityEvent('FAILED_LOGIN_UNKNOWN_EMAIL', ['email' => $email], 'warning');

            throw AuthSecurityException::invalidCredentials();
        }

        // 2) Temporarily locked account
        if ($user->isLocked()) {
            $this->loginHistory->record($user, $email, LoginStatus::LOCKED, $ip, $userAgent);
            throw AuthSecurityException::accountLocked((int) now()->diffInSeconds($user->locked_until));
        }

        // 3) Wrong password -> count it, lock on threshold
        if (!Hash::check($password, $user->password)) {
            $lockedNow = $this->userRepository->registerFailedAttempt(
                $user,
                (int) config('auth_security.lockout.max_attempts'),
                (int) config('auth_security.lockout.decay_minutes')
            );

            $this->loginHistory->record($user, $email, LoginStatus::FAILED, $ip, $userAgent);
            $this->logSecurityEvent($lockedNow ? 'ACCOUNT_LOCKED' : 'FAILED_LOGIN_ATTEMPT', [
                'user_id' => $user->id,
            ], 'warning');

            if ($lockedNow) {
                throw AuthSecurityException::accountLocked((int) config('auth_security.lockout.decay_minutes') * 60);
            }

            throw AuthSecurityException::invalidCredentials();
        }

        // 4) Correct password but account suspended (checked AFTER password to avoid enumeration)
        if ($user->status === UserStatus::SUSPENDED) {
            $this->loginHistory->record($user, $email, LoginStatus::BLOCKED, $ip, $userAgent);
            $this->logSecurityEvent('BLOCKED_LOGIN_SUSPENDED_ACCOUNT', ['user_id' => $user->id], 'warning');

            throw AuthSecurityException::accountSuspended();
        }

        // 5) Upgrade hash transparently if the hashing cost/algorithm changed
        if (Hash::needsRehash($user->password)) {
            $this->userRepository->forceUpdate($user, ['password' => $password]);
        }

        // 6) New-device alert (only if the user has logged in before)
        $hadPreviousLogins = $user->last_login_at !== null;
        $knownDevice = $this->loginHistory->isKnownDevice($user, $ip, $userAgent);

        $this->userRepository->recordSuccessfulLogin($user, (string) $ip);
        $this->loginHistory->record($user, $email, LoginStatus::SUCCESS, $ip, $userAgent);
        $tokens = $this->tokenService->issuePair($user, $ip, $userAgent);

        if ($hadPreviousLogins && !$knownDevice) {
            $this->safeNotify($user, new NewDeviceLoginNotification($ip, $userAgent));
        }

        $this->logSecurityEvent('SUCCESSFUL_LOGIN', ['user_id' => $user->id, 'new_device' => !$knownDevice]);

        return ['user' => $user->fresh(), 'tokens' => $tokens];
    }

    /**
     * Exchange a valid refresh token for a new token pair (rotation).
     */
    public function refresh(User $user, PersonalAccessToken $refreshToken, ?string $ip, ?string $userAgent): array
    {
        if ($user->status === UserStatus::SUSPENDED) {
            $this->tokenService->revokeAll($user);
            throw AuthSecurityException::accountSuspended();
        }

        $tokens = $this->tokenService->rotate($user, $refreshToken, $ip, $userAgent);
        $this->logSecurityEvent('TOKEN_REFRESHED', ['user_id' => $user->id, 'session_id' => $tokens['session_id']]);

        return $tokens;
    }

    /**
     * Logout the current device (destroys both access & refresh tokens of the session).
     */
    public function logout(User $user): void
    {
        /** @var PersonalAccessToken|null $current */
        $current = $user->currentAccessToken();

        if ($current?->session_id) {
            $this->tokenService->revokeSession($user, $current->session_id);
        } else {
            $current?->delete();
        }

        $this->logSecurityEvent('USER_LOGOUT', ['user_id' => $user->id]);
    }

    public function logoutAllDevices(User $user): void
    {
        $this->tokenService->revokeAll($user);
        $this->logSecurityEvent('USER_LOGOUT_ALL_DEVICES', ['user_id' => $user->id]);
    }

    /**
     * Change password (requires current password) and kill every other session.
     *
     * @throws ValidationException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword, ?string $ip): void
    {
        if (!Hash::check($currentPassword, $user->password)) {
            $this->logSecurityEvent('FAILED_PASSWORD_CHANGE_ATTEMPT', ['user_id' => $user->id], 'warning');

            throw ValidationException::withMessages([
                'current_password' => [__('messages.current_password_wrong')],
            ]);
        }

        $this->userRepository->forceUpdate($user, [
            'password'            => $newPassword,
            'password_changed_at' => now(),
        ]);

        /** @var PersonalAccessToken|null $current */
        $current = $user->currentAccessToken();
        $this->tokenService->revokeAllExcept($user, $current?->session_id);

        $this->safeNotify($user, new PasswordChangedNotification($ip));
        $this->logSecurityEvent('PASSWORD_CHANGED_SUCCESSFULLY', ['user_id' => $user->id]);
    }

    /**
     * Update allowed profile fields only.
     */
    public function updateProfile(User $user, array $data): User
    {
        $allowed = array_intersect_key($data, array_flip(['name', 'phone', 'avatar']));
        return $this->userRepository->update($user->id, $allowed);
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('motafawweq-timing-equaliser');
    }
}
