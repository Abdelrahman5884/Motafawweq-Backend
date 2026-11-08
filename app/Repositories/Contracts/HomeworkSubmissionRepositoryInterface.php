<?php

namespace App\Repositories\Contracts;

use App\Models\HomeworkSubmission;
use Illuminate\Database\Eloquent\Collection;

interface HomeworkSubmissionRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get all submissions for a homework with optional status and search filters.
     */
    public function getSubmissionsForHomework(int $homeworkId, array $filters = []): Collection;

    /**
     * Find a submission by UUID under the specified homework.
     */
    public function findByUuidForHomework(string $uuid, int $homeworkId): HomeworkSubmission;
}

