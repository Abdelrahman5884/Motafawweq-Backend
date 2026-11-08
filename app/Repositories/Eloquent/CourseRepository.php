<?php

namespace App\Repositories\Eloquent;

use App\Models\Course;
use App\Models\User;
use App\Repositories\Contracts\CourseRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CourseRepository extends BaseRepository implements CourseRepositoryInterface
{
    public function __construct(Course $model)
    {
        parent::__construct($model);
    }

    /**
     * Retrieve all courses belonging to the teacher, with optional search & stage/status filtering.
     */
    public function getForTeacher(int $teacherId, array $filters = []): Collection
    {
        $query = $this->model->forTeacher($teacherId);

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                    ->orWhere('title_en', 'like', "%{$search}%")
                    ->orWhere('grade_ar', 'like', "%{$search}%")
                    ->orWhere('subject_ar', 'like', "%{$search}%")
                    ->orWhere('description_ar', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['stage_id']) || !empty($filters['stage'])) {
            $stage = $filters['stage_id'] ?? $filters['stage'];
            $query->where('stage_id', $stage);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * Find a course by UUID and strictly enforce teacher ownership (Anti-IDOR).
     */
    public function findByUuidForTeacher(string $uuid, User $teacher): Course
    {
        /** @var Course|null $course */
        $course = $this->model->where('uuid', $uuid)->first();

        if (!$course) {
            throw (new ModelNotFoundException())->setModel(Course::class, [$uuid]);
        }

        $course->assertOwnedBy($teacher);

        return $course;
    }

    /**
     * Count active (non-deleted) courses for a teacher.
     */
    public function countActiveForTeacher(int $teacherId): int
    {
        return $this->model->forTeacher($teacherId)->count();
    }
}

