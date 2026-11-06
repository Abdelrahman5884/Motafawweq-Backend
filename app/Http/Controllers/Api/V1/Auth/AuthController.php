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

class AuthController extends BaseApiController
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * POST /auth/register
     */
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
        ], 'تم إنشاء الحساب بنجاح. تم إرسال رمز التأكيد إلى بريدك الإلكتروني.');
    }

    /**
     * POST /auth/login
     */
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
        ], 'تم تسجيل الدخول بنجاح');
    }

    /**
     * POST /auth/refresh  (Authorization: Bearer <refresh_token>)
     */
    public function refresh(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if (!$token instanceof PersonalAccessToken) {
            throw AuthSecurityException::invalidRefreshToken();
        }

        $tokens = $this->authService->refresh($request->user(), $token, $request->ip(), $request->userAgent());

        return $this->successResponse(new AuthTokenResource($tokens), 'تم تجديد الجلسة بنجاح');
    }

    /**
     * GET /auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse(
            new UserResource($request->user()),
            'تم جلب بيانات الملف الشخصي بنجاح'
        );
    }

    /**
     * PUT /auth/profile
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $updatedUser = $this->authService->updateProfile($request->user(), $request->validated());

        return $this->successResponse(new UserResource($updatedUser), 'تم تحديث بيانات الملف الشخصي بنجاح');
    }

    /**
     * POST /auth/change-password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->authService->changePassword(
            user: $request->user(),
            currentPassword: $request->validated('current_password'),
            newPassword: $request->validated('new_password'),
            ip: $request->ip()
        );

        return $this->successResponse(null, 'تم تغيير كلمة المرور بنجاح وتم تسجيل الخروج من باقي الأجهزة');
    }

    /**
     * POST /auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->successResponse(null, 'تم تسجيل الخروج بنجاح');
    }

    /**
     * POST /auth/logout-all
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAllDevices($request->user());

        return $this->successResponse(null, 'تم تسجيل الخروج من جميع الأجهزة بنجاح');
    }
}
