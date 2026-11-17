<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\UpdateStudentProfileRequest;
use App\Services\V1\Student\StudentProfileService;
use Illuminate\Http\JsonResponse;

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
}
