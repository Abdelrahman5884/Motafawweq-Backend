<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\V1\UserResource;
use App\Services\V1\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends BaseApiController
{
    public function __construct(
        protected UserService $userService
    ) {}

    /**
     * Display a listing of users (Admin only).
     */
    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->listUsers(
            keyword: $request->query('search'),
            role: $request->query('role'),
            perPage: (int) $request->query('per_page', 15)
        );

        return $this->paginatedResponse($users, UserResource::class);
    }

    /**
     * Display the specified user.
     */
    public function show(int|string $id): JsonResponse
    {
        $user = $this->userService->getUser($id);
        return $this->successResponse(new UserResource($user));
    }

    /**
     * Remove the specified user.
     */
    public function destroy(int|string $id): JsonResponse
    {
        $this->userService->deleteUser($id);
        return $this->successResponse(null, 'تم حذف المستخدم بنجاح');
    }
}
