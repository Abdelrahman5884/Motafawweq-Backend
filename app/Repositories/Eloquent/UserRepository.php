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
        return $this->model->where('email', $email)->first();
    }

    public function updateLastLogin(int $userId, string $ip): bool
    {
        return (bool) $this->model->where('id', $userId)->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }

    public function search(?string $keyword = null, ?string $role = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->query();

        if ($role) {
            $query->where('role', $role);
        }

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('email', 'like', "%{$keyword}%")
                  ->orWhere('phone', 'like', "%{$keyword}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }
}

