<?php

namespace App\Repositories\Contracts;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface ExamRepositoryInterface extends BaseRepositoryInterface
{
    public function getForTeacher(int $teacherId, array $filters = []): Collection;

    public function findByUuidForTeacher(string $uuid, User $teacher): Exam;

    public function countActiveForTeacher(int $teacherId): int;
}

