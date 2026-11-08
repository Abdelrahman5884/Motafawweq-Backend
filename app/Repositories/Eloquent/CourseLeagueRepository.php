<?php

namespace App\Repositories\Eloquent;

use App\Models\CourseLeague;
use App\Models\User;
use App\Repositories\Contracts\CourseLeagueRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CourseLeagueRepository extends BaseRepository implements CourseLeagueRepositoryInterface
{
    public function __construct(CourseLeague $model)
    {
        parent::__construct($model);
    }

    public function getForTeacher(int $teacherId, array $filters = []): Collection
    {
        $query = $this->model->forTeacher($teacherId)
            ->with(['course', 'studyGroup'])
            ->withCount('participants');

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                    ->orWhere('course_name_ar', 'like', "%{$search}%")
                    ->orWhere('group_name_ar', 'like', "%{$search}%");
            });
        }

        $courseId = $filters['course_id'] ?? null;
        if ($courseId) {
            $query->where(function ($q) use ($courseId) {
                $q->where('course_id', $courseId)
                    ->orWhereHas('course', fn ($c) => $c->where('uuid', $courseId));
            });
        }

        if (isset($filters['is_active'])) {
            $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        return $query->orderByDesc('is_active')->orderByDesc('created_at')->get();
    }

    public function findByUuidForTeacher(string $uuid, User $teacher): CourseLeague
    {
        /** @var CourseLeague|null $league */
        $league = $this->model->where('uuid', $uuid)
            ->with(['course', 'studyGroup'])
            ->first();

        if (!$league) {
            throw (new ModelNotFoundException())->setModel(CourseLeague::class, [$uuid]);
        }

        $league->assertOwnedBy($teacher);

        return $league;
    }
}

