<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', strtolower(trim($email)))->first();
    }

    public function recordSuccessfulLogin(User $user, string $ip): void
    {
        $user->forceFill([
            'last_login_at'         => now(),
            'last_login_ip'         => $ip,
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ])->save();
    }

    public function registerFailedAttempt(User $user, int $maxAttempts, int $lockMinutes): bool
    {
        // Atomic increment avoids race conditions under parallel brute-force requests
        $user->increment('failed_login_attempts');
        $user->refresh();

        if ($user->failed_login_attempts >= $maxAttempts) {
            $user->forceFill([
                'locked_until'          => now()->addMinutes($lockMinutes),
                'failed_login_attempts' => 0,
            ])->save();

            return true;
        }

        return false;
    }

    public function search(?string $keyword = null, ?string $role = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->query();

        if ($role) {
            $query->where('role', $role);
        }

        if ($keyword) {
            // Escape LIKE wildcards so user input can't craft expensive patterns
            $safe = addcslashes($keyword, '%_\\');

            $query->where(function ($q) use ($safe) {
                $q->where('name', 'like', "%{$safe}%")
                  ->orWhere('email', 'like', "%{$safe}%")
                  ->orWhere('phone', 'like', "%{$safe}%");
            });
        }

        return $query->latest()->paginate(min($perPage, 100));
    }
}
