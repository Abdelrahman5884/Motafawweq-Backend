<?php

namespace App\Repositories\Eloquent;

use App\Models\ExamSubmission;
use App\Repositories\Contracts\ExamSubmissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ExamSubmissionRepository extends BaseRepository implements ExamSubmissionRepositoryInterface
{
    public function __construct(ExamSubmission $model)
    {
        parent::__construct($model);
    }

    public function getSubmissionsForExam(int $examId, array $filters = []): Collection
    {
        $query = $this->model->where('exam_id', $examId)
            ->with(['student', 'grader']);

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('student_code', 'like', "%{$search}%");
            });
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if (isset($filters['is_passed'])) {
            $isPassed = filter_var($filters['is_passed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isPassed !== null) {
                $query->where('is_passed', $isPassed);
            }
        }

        return $query->orderByDesc('submitted_at')->orderByDesc('id')->get();
    }

    public function findByUuidForExam(string $uuid, int $examId): ExamSubmission
    {
        /** @var ExamSubmission|null $submission */
        $submission = $this->model->where('uuid', $uuid)
            ->where('exam_id', $examId)
            ->with(['student', 'grader', 'answers.question'])
            ->first();

        if (!$submission) {
            throw (new ModelNotFoundException())->setModel(ExamSubmission::class, [$uuid]);
        }

        return $submission;
    }
}
