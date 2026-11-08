<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\UpdateTeacherPreferencesRequest;
use App\Http\Requests\V1\Teacher\UpdateTeacherSettingsRequest;
use App\Http\Requests\V1\Teacher\UpdateTeacherStagesRequest;
use App\Http\Requests\V1\Teacher\UploadTeacherAvatarRequest;
use App\Http\Resources\V1\Teacher\TeacherSettingsResource;
use App\Services\V1\Teacher\TeacherSettingsService;
use Illuminate\Http\JsonResponse;

class TeacherSettingsController extends BaseApiController
{
    public function __construct(
        protected TeacherSettingsService $settingsService
    ) {}

    /**
     * GET /api/v1/teacher/settings
     * Fetch the teacher's full academic profile, curriculum stages, and preferences.
     */
    public function show(): JsonResponse
    {
        $profile = $this->settingsService->getSettings($this->authenticatedUser());

        return $this->successResponse(
            new TeacherSettingsResource($profile),
            __('messages.teacher_settings_fetched')
        );
    }

    /**
     * PUT /api/v1/teacher/settings
     * PUT /api/v1/teacher/settings/profile
     * Save all or partial teacher profile & settings changes.
     */
    public function update(UpdateTeacherSettingsRequest $request): JsonResponse
    {
        $profile = $this->settingsService->updateSettings(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->successResponse(
            new TeacherSettingsResource($profile),
            __('messages.teacher_settings_updated')
        );
    }

    /**
     * PUT /api/v1/teacher/settings/stages
     * Update only the curriculum stages & grades taught by the teacher.
     */
    public function updateStages(UpdateTeacherStagesRequest $request): JsonResponse
    {
        $profile = $this->settingsService->updateStages(
            $this->authenticatedUser(),
            $request->validated('stages_taught')
        );

        return $this->successResponse(
            new TeacherSettingsResource($profile),
            __('messages.teacher_stages_updated')
        );
    }

    /**
     * PUT /api/v1/teacher/settings/preferences
     * Update theme, language, notification, and watermark preferences.
     */
    public function updatePreferences(UpdateTeacherPreferencesRequest $request): JsonResponse
    {
        $profile = $this->settingsService->updatePreferences(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->successResponse(
            new TeacherSettingsResource($profile),
            __('messages.teacher_preferences_updated')
        );
    }

    /**
     * POST /api/v1/teacher/settings/avatar
     * Upload and verify a new teacher portrait image.
     */
    public function uploadAvatar(UploadTeacherAvatarRequest $request): JsonResponse
    {
        $profile = $this->settingsService->uploadAvatar(
            $this->authenticatedUser(),
            $request->file('avatar')
        );

        return $this->successResponse(
            new TeacherSettingsResource($profile),
            __('messages.teacher_avatar_updated')
        );
    }
}

