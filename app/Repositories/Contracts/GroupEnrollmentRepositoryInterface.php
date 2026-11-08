<?php

namespace App\Repositories\Contracts;

use App\Models\GroupEnrollment;
use Illuminate\Database\Eloquent\Collection;

interface GroupEnrollmentRepositoryInterface extends BaseRepositoryInterface
{
    public function getEnrolledForGroup(int $groupId, ?string $search = null): Collection;

    public function getPendingForGroup(int $groupId, ?string $search = null): Collection;

    public function findInGroup(int $groupId, string $enrollmentUuid): ?GroupEnrollment;

    public function findByStudentAndGroup(int $studentId, int $groupId): ?GroupEnrollment;

    public function countEnrolledInGroup(int $groupId): int;
}

