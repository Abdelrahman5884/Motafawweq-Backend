<?php

namespace App\Repositories\Eloquent;

use App\Models\StudyGroup;
use App\Models\User;
use App\Repositories\Contracts\StudyGroupRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StudyGroupRepository extends BaseRepository implements StudyGroupRepositoryInterface
{
    public function __construct(StudyGroup $model)
    {
        parent::__construct($model);
    }

    public function getForTeacher(int $teacherId, array $filters = []): Collection
    {
        $query = $this->model->forTeacher($teacherId)
            ->with(['scheduleSlots'])
            ->withCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ]);

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('center_name', 'like', "%{$search}%")
                    ->orWhere('hall_name', 'like', "%{$search}%")
                    ->orWhere('subject_ar', 'like', "%{$search}%")
                    ->orWhere('join_code', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['center_name']) && $filters['center_name'] !== 'all') {
            $query->where('center_name', $filters['center_name']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function findByUuidForTeacher(string $uuid, User $teacher): StudyGroup
    {
        /** @var StudyGroup|null $group */
        $group = $this->model->where('uuid', $uuid)
            ->with(['scheduleSlots', 'course'])
            ->withCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ])
            ->first();

        if (!$group) {
            throw (new ModelNotFoundException())->setModel(StudyGroup::class, [$uuid]);
        }

        $group->assertOwnedBy($teacher);

        return $group;
    }

    public function findByJoinCode(string $code): ?StudyGroup
    {
        return $this->model->where('join_code', strtoupper(trim($code)))
            ->with(['scheduleSlots', 'teacher'])
            ->first();
    }

    public function countActiveForTeacher(int $teacherId): int
    {
        return $this->model->forTeacher($teacherId)
            ->where('status', 'active')
            ->count();
    }
}

