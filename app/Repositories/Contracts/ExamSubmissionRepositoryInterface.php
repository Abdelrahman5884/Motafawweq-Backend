<?php

namespace App\Repositories\Contracts;

use App\Models\ExamSubmission;
use Illuminate\Database\Eloquent\Collection;

interface ExamSubmissionRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get all submissions for an exam with optional search, status and pass filters.
     */
    public function getSubmissionsForExam(int $examId, array $filters = []): Collection;

    /**
     * Find a specific submission by UUID under the specified exam.
     */
    public function findByUuidForExam(string $uuid, int $examId): ExamSubmission;
}

