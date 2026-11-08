<?php

namespace App\Repositories\Contracts;

use App\Models\TeacherPayoutAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface TeacherPayoutAccountRepositoryInterface extends BaseRepositoryInterface
{
    public function getAllForTeacher(int $teacherId): Collection;

    public function findByUuidForTeacher(string $uuid, User $teacher): TeacherPayoutAccount;

    public function seedDefaultsIfEmpty(User $teacher): Collection;
}

