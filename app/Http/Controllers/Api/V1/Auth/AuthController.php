<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\AuthSecurityException;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Http\Requests\V1\Auth\RegisterRequest;
use App\Http\Requests\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\V1\AuthTokenResource;
use App\Http\Resources\V1\UserResource;
use App\Models\PersonalAccessToken;
use App\Services\V1\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends BaseApiController
{
    public function __construct(
        protected AuthService $authService
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register(
            $request->validated(),
            $request->ip(),
            $request->userAgent()
        );

        return $this->createdResponse([
            'user'   => new UserResource($result['user']),
            'tokens' => new AuthTokenResource($result['tokens']),
        ], __('messages.register_success'));
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
            ip: $request->ip(),
            userAgent: $request->userAgent()
        );

        return $this->successResponse([
            'user'   => new UserResource($result['user']),
            'tokens' => new AuthTokenResource($result['tokens']),
        ], __('messages.login_success'));
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (!$token instanceof PersonalAccessToken) {
            throw AuthSecurityException::invalidRefreshToken();
        }

        $tokens = $this->authService->refresh($request->user(), $token, $request->ip(), $request->userAgent());

        return $this->successResponse(new AuthTokenResource($tokens), __('messages.refresh_success'));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->successResponse(
            new UserResource($request->user()),
            __('messages.profile_fetched')
        );
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $updatedUser = $this->authService->updateProfile($request->user(), $request->validated());

        return $this->successResponse(new UserResource($updatedUser), __('messages.profile_updated'));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authService->changePassword(
            user: $request->user(),
            currentPassword: $request->validated('current_password'),
            newPassword: $request->validated('new_password'),
            ip: $request->ip()
        );

        return $this->successResponse(null, __('messages.password_changed'));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, __('messages.logout_success'));
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAllDevices($request->user());

        return $this->successResponse(null, __('messages.logout_all_success'));
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (!Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['كلمة المرور غير صحيحة.'],
            ]);
        }

        $user->tokens()->delete();
        $user->delete();

        return $this->successResponse(null, 'تم حذف الحساب نهائياً بنجاح.');
    }
}
