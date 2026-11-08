<?php

namespace App\Repositories\Contracts;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface CourseRepositoryInterface extends BaseRepositoryInterface
{
    public function getForTeacher(int $teacherId, array $filters = []): Collection;

    public function findByUuidForTeacher(string $uuid, User $teacher): Course;

    public function countActiveForTeacher(int $teacherId): int;
}

