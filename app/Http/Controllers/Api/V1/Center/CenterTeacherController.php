<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\StoreCenterTeacherRequest;
use App\Http\Requests\V1\Center\UpdateCenterTeacherRequest;
use App\Services\V1\Center\CenterConnectService;
use App\Services\V1\Center\CenterTeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CenterTeacherController extends BaseApiController
{
    public function __construct(
        protected CenterTeacherService $teacherService,
        protected CenterConnectService $connectService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $teachers = $this->teacherService->listTeachers(
            $this->authenticatedUser(),
            $request->only(['branch_id', 'status', 'search'])
        );

        return $this->successResponse(
            $teachers,
            __('messages.center.teachers_fetched'),
            meta: ['total' => count($teachers)]
        );
    }

    public function store(StoreCenterTeacherRequest $request): JsonResponse
    {
        $teacher = $this->teacherService->createTeacher(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse($teacher, __('messages.center.teacher_created'));
    }

    public function show(string $uuid): JsonResponse
    {
        $teacher = $this->teacherService->findOwnedTeacher($this->authenticatedUser(), $uuid);

        return $this->successResponse(
            $this->teacherService->formatTeacher($teacher),
            __('messages.center.teacher_fetched')
        );
    }

    public function update(UpdateCenterTeacherRequest $request, string $uuid): JsonResponse
    {
        $teacher = $this->teacherService->updateTeacher(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse($teacher, __('messages.center.teacher_updated'));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->teacherService->deleteTeacher($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.center.teacher_deleted'));
    }

    public function resendInvite(string $uuid): JsonResponse
    {
        $result = $this->teacherService->resendInvite($this->authenticatedUser(), $uuid);

        return $this->successResponse($result, __('messages.center.teacher_invite_resent'));
    }

    /**
     * GET /api/v1/center/connect/search?q=...&role=teacher|student
     */
    public function searchGlobalUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'role' => ['nullable', 'string', Rule::in(['teacher', 'student'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $results = $this->connectService->searchGlobalUsers(
            $this->authenticatedUser(),
            $validated['q'],
            $validated['role'] ?? null,
            (int) ($validated['limit'] ?? 15)
        );

        return $this->successResponse(
            $results,
            __('messages.center.connect_search_fetched'),
            meta: ['total' => count($results)]
        );
    }

    /**
     * GET /api/v1/center/connect/users
     */
    public function listConnectedUsers(Request $request): JsonResponse
    {
        $users = $this->connectService->listConnectedUsers(
            $this->authenticatedUser(),
            $request->query('role'),
            $request->query('branch_id')
        );

        return $this->successResponse(
            $users,
            __('messages.center.connect_users_fetched'),
            meta: ['total' => count($users)]
        );
    }

    /**
     * POST /api/v1/center/connect/users
     */
    public function connectUser(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'user_id' => $request->input('user_id', $request->input('userId')),
            'branch_id' => $request->input('branch_id', $request->input('branchId')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'uuid'],
        ]);

        $connection = $this->connectService->connectUser(
            $this->authenticatedUser(),
            (int) $validated['user_id'],
            $validated['branch_id'] ?? null
        );

        return $this->createdResponse($connection, __('messages.center.connect_user_linked'));
    }

    /**
     * DELETE /api/v1/center/connect/users/{uuid}
     */
    public function disconnectUser(string $uuid): JsonResponse
    {
        $this->connectService->disconnectUser($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.center.connect_user_unlinked'));
    }
}
