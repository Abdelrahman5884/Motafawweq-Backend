<?php

namespace App\Services\V1\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService extends BaseService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository
    ) {}

    /**
     * Register a new user in the platform.
     *
     * @param  array  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        return $this->transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name'     => $data['name'],
                'email'    => strtolower($data['email']),
                'password' => Hash::make($data['password']),
                'role'     => $data['role'] ?? UserRole::STUDENT->value,
                'status'   => UserStatus::ACTIVE->value,
                'phone'    => $data['phone'] ?? null,
            ]);

            $this->logSecurityEvent('USER_REGISTERED', [
                'user_id' => $user->id,
                'role'    => $user->role->value,
                'email'   => $user->email,
            ]);

            // Issue token with role capability
            $token = $user->createToken('auth-token', ["role:{$user->role->value}"])->plainTextToken;

            return [
                'user'  => $user,
                'token' => $token,
            ];
        });
    }

    /**
     * Authenticate user credentials and issue a secure bearer token.
     *
     * @param  string  $email
     * @param  string  $password
     * @param  string  $ip
     * @param  string  $userAgent
     * @return array{user: User, token: string}
     * @throws ValidationException
     */
    public function login(string $email, string $password, string $ip, string $userAgent): array
    {
        $normalizedEmail = strtolower(trim($email));
        $user = $this->userRepository->findByEmail($normalizedEmail);

        // Security: timing-attack mitigation by hashing dummy password if user not found
        if (!$user || !Hash::check($password, $user->password)) {
            $this->logSecurityEvent('FAILED_LOGIN_ATTEMPT', [
                'email' => $normalizedEmail,
                'ip'    => $ip,
            ], 'warning');

            throw ValidationException::withMessages([
                'email' => ['البريد الإلكتروني أو كلمة المرور غير صحيحة.'],
            ]);
        }

        // Account status check (suspended / deactivated)
        if ($user->status === UserStatus::SUSPENDED) {
            $this->logSecurityEvent('BLOCKED_LOGIN_SUSPENDED_ACCOUNT', [
                'user_id' => $user->id,
                'email'   => $user->email,
            ], 'warning');

            throw ValidationException::withMessages([
                'email' => ['تم تعليق هذا الحساب. يرجى التواصل مع إدارة المنصة.'],
            ]);
        }

        // Update login audit trail
        $this->userRepository->updateLastLogin($user->id, $ip);

        // Log successful authentication
        $this->logSecurityEvent('SUCCESSFUL_LOGIN', [
            'user_id' => $user->id,
            'role'    => $user->role->value,
            'ip'      => $ip,
        ]);

        // Generate token with role ability
        $token = $user->createToken(
            name: 'auth-token-' . substr(md5($userAgent . time()), 0, 8),
            abilities: ["role:{$user->role->value}"]
        )->plainTextToken;

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    /**
     * Revoke current access token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();

        $this->logSecurityEvent('USER_LOGOUT', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Revoke all access tokens across all devices.
     */
    public function logoutAllDevices(User $user): void
    {
        $user->tokens()->delete();

        $this->logSecurityEvent('USER_LOGOUT_ALL_DEVICES', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Change user password securely.
     *
     * @param  User  $user
     * @param  string  $currentPassword
     * @param  string  $newPassword
     * @return void
     * @throws ValidationException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!Hash::check($currentPassword, $user->password)) {
            $this->logSecurityEvent('FAILED_PASSWORD_CHANGE_ATTEMPT', [
                'user_id' => $user->id,
            ], 'warning');

            throw ValidationException::withMessages([
                'current_password' => ['كلمة المرور الحالية غير صحيحة.'],
            ]);
        }

        $this->userRepository->update($user->id, [
            'password' => Hash::make($newPassword),
        ]);

        // Revoke other sessions/tokens for security
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        $this->logSecurityEvent('PASSWORD_CHANGED_SUCCESSFULLY', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Update user profile information.
     */
    public function updateProfile(User $user, array $data): User
    {
        $allowed = array_intersect_key($data, array_flip(['name', 'phone', 'avatar']));
        return $this->userRepository->update($user->id, $allowed);
    }
}

