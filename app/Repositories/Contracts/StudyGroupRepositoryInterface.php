<?php

namespace App\Repositories\Contracts;

use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface StudyGroupRepositoryInterface extends BaseRepositoryInterface
{
    public function getForTeacher(int $teacherId, array $filters = []): Collection;

    public function findByUuidForTeacher(string $uuid, User $teacher): StudyGroup;

    public function findByJoinCode(string $code): ?StudyGroup;

    public function countActiveForTeacher(int $teacherId): int;
}

