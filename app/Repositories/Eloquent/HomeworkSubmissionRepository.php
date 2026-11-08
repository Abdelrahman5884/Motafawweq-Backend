<?php

namespace App\Repositories\Eloquent;

use App\Models\HomeworkSubmission;
use App\Repositories\Contracts\HomeworkSubmissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class HomeworkSubmissionRepository extends BaseRepository implements HomeworkSubmissionRepositoryInterface
{
    public function __construct(HomeworkSubmission $model)
    {
        parent::__construct($model);
    }

    public function getSubmissionsForHomework(int $homeworkId, array $filters = []): Collection
    {
        $query = $this->model->where('homework_id', $homeworkId)
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

        $location = trim((string) ($filters['location_ar'] ?? $filters['location'] ?? ''));
        if ($location !== '' && $location !== 'all') {
            $query->where('location_ar', 'like', "%{$location}%");
        }

        return $query->orderByDesc('submitted_at')->orderByDesc('id')->get();
    }

    public function findByUuidForHomework(string $uuid, int $homeworkId): HomeworkSubmission
    {
        /** @var HomeworkSubmission|null $submission */
        $submission = $this->model->where('uuid', $uuid)
            ->where('homework_id', $homeworkId)
            ->with(['student', 'grader', 'homework'])
            ->first();

        if (!$submission) {
            throw (new ModelNotFoundException())->setModel(HomeworkSubmission::class, [$uuid]);
        }

        return $submission;
    }
}

