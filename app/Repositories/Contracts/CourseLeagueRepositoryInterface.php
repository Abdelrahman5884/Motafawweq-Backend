<?php

namespace App\Repositories\Contracts;

use App\Models\CourseLeague;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface CourseLeagueRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get all course leagues for a teacher with optional course and active filters.
     */
    public function getForTeacher(int $teacherId, array $filters = []): Collection;

    /**
     * Find a league by UUID and enforce teacher multi-tenant ownership.
     */
    public function findByUuidForTeacher(string $uuid, User $teacher): CourseLeague;
}

