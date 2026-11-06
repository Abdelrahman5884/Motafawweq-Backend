<?php

namespace App\Repositories\Eloquent;

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

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

    public function findStudentByCode(string $code): ?User
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }

        return $this->model
            ->where('role', UserRole::STUDENT->value)
            ->where('student_code', $normalized)
            ->first();
    }

    public function generateUniqueStudentCode(): string
    {
        do {
            $code = 'STD-' . strtoupper(Str::random(6));
        } while ($this->model->where('student_code', $code)->exists());

        return $code;
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
