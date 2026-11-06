<?php

namespace App\Repositories\Contracts;

use App\Enums\VerificationCodeType;
use App\Models\User;
use App\Models\VerificationCode;

interface VerificationCodeRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Latest still-usable (not used, not expired) code of a type for a user.
     */
    public function findActive(User $user, VerificationCodeType $type): ?VerificationCode;

    /**
     * Latest code of a type regardless of state (used for resend cooldown).
     */
    public function findLatest(User $user, VerificationCodeType $type): ?VerificationCode;

    /**
     * Invalidate every outstanding code of a type for a user.
     */
    public function invalidateAll(User $user, VerificationCodeType $type): void;

    /**
     * Delete expired / used codes older than the given hours.
     */
    public function pruneStale(int $olderThanHours = 24): int;
}

