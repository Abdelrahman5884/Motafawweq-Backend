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
     * Record a successful login: stamp time/IP and clear the lockout counters.
     */
    public function recordSuccessfulLogin(User $user, string $ip): void;

    /**
     * Increment failed attempts and lock the account when the threshold is reached.
     * Returns true when this failure caused the account to become locked.
     */
    public function registerFailedAttempt(User $user, int $maxAttempts, int $lockMinutes): bool;

    /**
     * Search users by role and keyword.
     */
    public function search(?string $keyword = null, ?string $role = null, int $perPage = 15): LengthAwarePaginator;
}
