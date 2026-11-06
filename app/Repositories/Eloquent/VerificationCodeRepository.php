<?php

namespace App\Repositories\Eloquent;

use App\Enums\VerificationCodeType;
use App\Models\User;
use App\Models\VerificationCode;
use App\Repositories\Contracts\VerificationCodeRepositoryInterface;

class VerificationCodeRepository extends BaseRepository implements VerificationCodeRepositoryInterface
{
    public function __construct(VerificationCode $model)
    {
        parent::__construct($model);
    }

    public function findActive(User $user, VerificationCodeType $type): ?VerificationCode
    {
        return $this->model
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    public function findLatest(User $user, VerificationCodeType $type): ?VerificationCode
    {
        return $this->model
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->latest('id')
            ->first();
    }

    public function invalidateAll(User $user, VerificationCodeType $type): void
    {
        $this->model
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    public function pruneStale(int $olderThanHours = 24): int
    {
        return $this->model
            ->where('expires_at', '<', now()->subHours($olderThanHours))
            ->delete();
    }
}

