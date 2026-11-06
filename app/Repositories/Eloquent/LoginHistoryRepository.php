<?php

namespace App\Repositories\Eloquent;

use App\Enums\LoginStatus;
use App\Models\LoginHistory;
use App\Models\User;
use App\Repositories\Contracts\LoginHistoryRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class LoginHistoryRepository extends BaseRepository implements LoginHistoryRepositoryInterface
{
    public function __construct(LoginHistory $model)
    {
        parent::__construct($model);
    }

    public function record(?User $user, ?string $email, LoginStatus $status, ?string $ip, ?string $userAgent): void
    {
        $this->model->create([
            'user_id'    => $user?->id,
            'email'      => $email ? Str::limit(strtolower($email), 250, '') : null,
            'status'     => $status->value,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? Str::limit($userAgent, 500, '') : null,
        ]);
    }

    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate(min($perPage, 100));
    }

    public function isKnownDevice(User $user, ?string $ip, ?string $userAgent): bool
    {
        return $this->model
            ->where('user_id', $user->id)
            ->where('status', LoginStatus::SUCCESS->value)
            ->where('ip_address', $ip)
            ->where('user_agent', $userAgent ? Str::limit($userAgent, 500, '') : null)
            ->exists();
    }
}

