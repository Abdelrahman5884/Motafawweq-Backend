<?php

namespace App\Repositories\Contracts;

use App\Models\TeacherProfile;
use App\Models\User;

interface TeacherProfileRepositoryInterface extends BaseRepositoryInterface
{
    public function findByUserId(int $userId): ?TeacherProfile;

    public function firstOrCreateForTeacher(User $teacher, array $defaults = []): TeacherProfile;
}

