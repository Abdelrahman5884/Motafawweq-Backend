<?php

namespace App\Repositories\Eloquent;

use App\Models\Exam;
use App\Models\User;
use App\Repositories\Contracts\ExamRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ExamRepository extends BaseRepository implements ExamRepositoryInterface
{
    public function __construct(Exam $model)
    {
        parent::__construct($model);
    }

    public function getForTeacher(int $teacherId, array $filters = []): Collection
    {
        $query = $this->model->forTeacher($teacherId)
            ->with(['course', 'questions'])
            ->withCount('questions');

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                    ->orWhere('title_en', 'like', "%{$search}%")
                    ->orWhere('course_title_ar', 'like', "%{$search}%")
                    ->orWhere('grade_ar', 'like', "%{$search}%");
            });
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function findByUuidForTeacher(string $uuid, User $teacher): Exam
    {
        /** @var Exam|null $exam */
        $exam = $this->model->where('uuid', $uuid)
            ->with(['course', 'questions'])
            ->first();

        if (!$exam) {
            throw (new ModelNotFoundException())->setModel(Exam::class, [$uuid]);
        }

        $exam->assertOwnedBy($teacher);

        return $exam;
    }

    public function countActiveForTeacher(int $teacherId): int
    {
        return $this->model->forTeacher($teacherId)
            ->where('status', 'active')
            ->count();
    }
}

