<?php

namespace App\Repositories\Contracts;

use App\Models\Homework;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface HomeworkRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get all homeworks created by the teacher with optional filters.
     */
    public function getForTeacher(int $teacherId, array $filters = []): Collection;

    /**
     * Find a homework by UUID and enforce teacher multi-tenant ownership.
     */
    public function findByUuidForTeacher(string $uuid, User $teacher): Homework;
}

