<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\UpdateStudentProfileRequest;
use App\Services\V1\Student\StudentProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends BaseApiController
{
    public function __construct(
        protected StudentProfileService $profileService
    ) {}

    /**
     * GET /api/v1/student/profile
     */
    public function show(): JsonResponse
    {
        $profile = $this->profileService->getProfile($this->authenticatedUser());

        return $this->successResponse($profile);
    }

    /**
     * PUT /api/v1/student/profile
     */
    public function update(UpdateStudentProfileRequest $request): JsonResponse
    {
        $profile = $this->profileService->updateProfile(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->successResponse($profile, 'تم تحديث بيانات الملف الشخصي بنجاح.');
    }

    /**
     * POST /api/v1/student/profile/avatar
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $profile = $this->profileService->uploadAvatar(
            $this->authenticatedUser(),
            $request->file('avatar')
        );

        return $this->successResponse($profile, 'تم تحديث الصورة الشخصية بنجاح.');
    }
}
