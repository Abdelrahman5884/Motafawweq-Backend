<?php

namespace App\Services\V1;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

class UserService extends BaseService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository
    ) {}

    /**
     * Get paginated list of users with search and filter.
     */
    public function listUsers(?string $keyword = null, ?string $role = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->userRepository->search($keyword, $role, $perPage);
    }

    /**
     * Find a user by ID.
     */
    public function getUser(int|string $id): User
    {
        return $this->userRepository->findOrFail($id);
    }

    /**
     * Update user details (admin action).
     */
    public function updateUser(int|string $id, array $data): User
    {
        return $this->userRepository->update($id, $data);
    }

    /**
     * Delete user account.
     */
    public function deleteUser(int|string $id): bool
    {
        $this->logSecurityEvent('USER_DELETED', ['target_user_id' => $id], 'warning');
        return $this->userRepository->delete($id);
    }
}

