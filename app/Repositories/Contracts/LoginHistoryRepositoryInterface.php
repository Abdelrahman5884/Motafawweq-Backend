<?php

namespace App\Repositories\Contracts;

use App\Enums\LoginStatus;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface LoginHistoryRepositoryInterface extends BaseRepositoryInterface
{
    public function record(?User $user, ?string $email, LoginStatus $status, ?string $ip, ?string $userAgent): void;

    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator;

    /**
     * Has this user ever logged in successfully from this IP + user agent?
     */
    public function isKnownDevice(User $user, ?string $ip, ?string $userAgent): bool;
}

