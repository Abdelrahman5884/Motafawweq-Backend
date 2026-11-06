<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find a user by their email address.
     */
    public function findByEmail(string $email): ?User;

    /**
     * Update user's last login timestamp and IP.
     */
    public function updateLastLogin(int $userId, string $ip): bool;

    /**
     * Search users by role and keyword.
     */
    public function search(?string $keyword = null, ?string $role = null, int $perPage = 15): LengthAwarePaginator;
}

