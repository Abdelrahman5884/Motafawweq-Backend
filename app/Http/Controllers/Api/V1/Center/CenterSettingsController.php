<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\ImportCenterStudentsRequest;
use App\Http\Requests\V1\Center\UpdateCenterSettingsRequest;
use App\Http\Requests\V1\Center\UploadCenterAvatarRequest;
use App\Services\V1\Center\CenterSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CenterSettingsController extends BaseApiController
{
    public function __construct(
        protected CenterSettingsService $settingsService
    ) {}

    public function show(): JsonResponse
    {
        $settings = $this->settingsService->getSettings($this->authenticatedUser());

        return $this->successResponse($settings, __('messages.center.settings_fetched'));
    }

    public function update(UpdateCenterSettingsRequest $request): JsonResponse
    {
        $settings = $this->settingsService->updateSettings(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->successResponse($settings, __('messages.center.settings_updated'));
    }

    public function uploadAvatar(UploadCenterAvatarRequest $request): JsonResponse
    {
        $settings = $this->settingsService->uploadAvatar(
            $this->authenticatedUser(),
            $request->file('avatar')
        );

        return $this->successResponse($settings, __('messages.center.settings_updated'));
    }

    public function deleteAvatar(): JsonResponse
    {
        $settings = $this->settingsService->deleteAvatar($this->authenticatedUser());

        return $this->successResponse($settings, __('messages.center.settings_updated'));
    }

    public function updateKpis(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'target_attendance_pct' => $request->input('target_attendance_pct', $request->input('targetAttendance')),
            'target_monthly_revenue_egp' => $request->input('target_monthly_revenue_egp', $request->input('targetRevenue')),
            'target_room_utilization_pct' => $request->input('target_room_utilization_pct', $request->input('targetUtilization')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'target_attendance_pct' => ['sometimes', 'integer', 'min:50', 'max:100'],
            'target_monthly_revenue_egp' => ['sometimes', 'numeric', 'min:10000', 'max:100000000'],
            'target_room_utilization_pct' => ['sometimes', 'integer', 'min:30', 'max:100'],
        ]);

        $settings = $this->settingsService->updateKpiTargets($this->authenticatedUser(), $validated);

        return $this->successResponse($settings, __('messages.center.kpis_updated'));
    }

    public function tasks(Request $request): JsonResponse
    {
        $tasks = $this->settingsService->listTasks(
            $this->authenticatedUser(),
            $request->only(['completed'])
        );

        return $this->successResponse(
            $tasks,
            __('messages.center.tasks_fetched'),
            meta: ['total' => count($tasks)]
        );
    }

    public function storeTask(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'title_ar' => $request->input('title_ar', $request->input('titleAr')),
            'assignee_name' => $request->input('assignee_name', $request->input('assignee')),
            'deadline_label' => $request->input('deadline_label', $request->input('deadline')),
            'branch_id' => $request->input('branch_id', $request->input('branchId')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'title_ar' => ['required', 'string', 'min:2', 'max:1000'],
            'assignee_name' => ['nullable', 'string', 'max:150'],
            'deadline_label' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'uuid'],
        ]);

        $task = $this->settingsService->createTask($this->authenticatedUser(), $validated);

        return $this->createdResponse($task, __('messages.center.task_created'));
    }

    public function updateTask(Request $request, string $uuid): JsonResponse
    {
        $request->merge(array_filter([
            'title_ar' => $request->input('title_ar', $request->input('titleAr')),
            'assignee_name' => $request->input('assignee_name', $request->input('assignee')),
            'deadline_label' => $request->input('deadline_label', $request->input('deadline')),
            'is_completed' => $request->input('is_completed', $request->input('completed')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'title_ar' => ['sometimes', 'string', 'min:2', 'max:1000'],
            'assignee_name' => ['nullable', 'string', 'max:150'],
            'deadline_label' => ['nullable', 'string', 'max:120'],
            'is_completed' => ['sometimes', 'boolean'],
        ]);

        $task = $this->settingsService->updateTask($this->authenticatedUser(), $uuid, $validated);

        return $this->successResponse($task, __('messages.center.task_updated'));
    }

    public function toggleTask(string $uuid): JsonResponse
    {
        $task = $this->settingsService->toggleTask($this->authenticatedUser(), $uuid);

        return $this->successResponse($task, __('messages.center.task_toggled'));
    }

    public function destroyTask(string $uuid): JsonResponse
    {
        $this->settingsService->deleteTask($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.center.task_deleted'));
    }

    public function announcements(): JsonResponse
    {
        $items = $this->settingsService->listAnnouncements($this->authenticatedUser());

        return $this->successResponse(
            $items,
            __('messages.center.announcements_fetched'),
            meta: ['total' => count($items)]
        );
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'title_ar' => $request->input('title_ar', $request->input('titleAr')),
            'content_ar' => $request->input('content_ar', $request->input('contentAr')),
            'branch_id' => $request->input('branch_id', $request->input('branchId')),
            'branch_label' => $request->input('branch_label', $request->input('branch')),
            'author_name' => $request->input('author_name', $request->input('author')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'title_ar' => ['required', 'string', 'min:2', 'max:200'],
            'content_ar' => ['required', 'string', 'min:3', 'max:3000'],
            'branch_id' => ['nullable', 'uuid'],
            'branch_label' => ['nullable', 'string', 'max:150'],
            'author_name' => ['nullable', 'string', 'max:150'],
        ]);

        $ann = $this->settingsService->createAnnouncement($this->authenticatedUser(), $validated);

        return $this->createdResponse($ann, __('messages.center.announcement_created'));
    }

    public function destroyAnnouncement(string $uuid): JsonResponse
    {
        $this->settingsService->deleteAnnouncement($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.center.announcement_deleted'));
    }

    public function importTemplate(): Response
    {
        $csv = $this->settingsService->getStudentImportTemplateCsv();

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="center-students-template.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function importStudents(ImportCenterStudentsRequest $request): JsonResponse
    {
        $v = $request->validated();

        $result = $this->settingsService->importStudents(
            $this->authenticatedUser(),
            $v['rows'],
            $v['default_group_id'] ?? null
        );

        return $this->successResponse($result, __('messages.center.students_imported'));
    }

    public function backups(): JsonResponse
    {
        $backups = $this->settingsService->listBackups($this->authenticatedUser());

        return $this->successResponse(
            $backups,
            __('messages.center.backups_fetched'),
            meta: ['total' => count($backups)]
        );
    }

    public function createBackup(): JsonResponse
    {
        $backup = $this->settingsService->createBackup($this->authenticatedUser());

        return $this->createdResponse($backup, __('messages.center.backup_created'));
    }
}
