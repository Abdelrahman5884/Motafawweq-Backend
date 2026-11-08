<?php

namespace App\Repositories\Eloquent;

use App\Models\Homework;
use App\Models\User;
use App\Repositories\Contracts\HomeworkRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class HomeworkRepository extends BaseRepository implements HomeworkRepositoryInterface
{
    public function __construct(Homework $model)
    {
        parent::__construct($model);
    }

    public function getForTeacher(int $teacherId, array $filters = []): Collection
    {
        $query = $this->model->forTeacher($teacherId)
            ->with(['course', 'lesson']);

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                    ->orWhere('course_title_ar', 'like', "%{$search}%")
                    ->orWhere('lesson_title_ar', 'like', "%{$search}%")
                    ->orWhere('target_location_ar', 'like', "%{$search}%");
            });
        }

        $location = strtolower(trim((string) ($filters['target_location_id'] ?? $filters['location'] ?? '')));
        if ($location !== '' && $location !== 'all') {
            $query->where('target_location_id', $location);
        }

        $courseId = $filters['course_id'] ?? null;
        if ($courseId) {
            $query->where(function ($q) use ($courseId) {
                $q->where('course_id', $courseId)
                    ->orWhereHas('course', fn ($c) => $c->where('uuid', $courseId));
            });
        }

        $type = strtolower(trim((string) ($filters['type'] ?? '')));
        if ($type !== '' && $type !== 'all') {
            $query->where('type', $type);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function findByUuidForTeacher(string $uuid, User $teacher): Homework
    {
        /** @var Homework|null $homework */
        $homework = $this->model->where('uuid', $uuid)
            ->with(['course', 'lesson'])
            ->first();

        if (!$homework) {
            throw (new ModelNotFoundException())->setModel(Homework::class, [$uuid]);
        }

        $homework->assertOwnedBy($teacher);

        return $homework;
    }
}

