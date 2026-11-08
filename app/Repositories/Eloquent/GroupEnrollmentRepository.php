<?php

namespace App\Repositories\Eloquent;

use App\Enums\EnrollmentStatus;
use App\Models\GroupEnrollment;
use App\Repositories\Contracts\GroupEnrollmentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class GroupEnrollmentRepository extends BaseRepository implements GroupEnrollmentRepositoryInterface
{
    public function __construct(GroupEnrollment $model)
    {
        parent::__construct($model);
    }

    public function getEnrolledForGroup(int $groupId, ?string $search = null): Collection
    {
        $query = $this->model->where('study_group_id', $groupId)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->with(['student']);

        if (!empty($search)) {
            $this->applySearchFilter($query, trim($search));
        }

        return $query->orderByDesc('enrolled_at')->orderBy('id')->get();
    }

    public function getPendingForGroup(int $groupId, ?string $search = null): Collection
    {
        $query = $this->model->where('study_group_id', $groupId)
            ->where('status', EnrollmentStatus::PENDING->value)
            ->with(['student']);

        if (!empty($search)) {
            $this->applySearchFilter($query, trim($search));
        }

        return $query->orderByDesc('requested_at')->orderByDesc('id')->get();
    }

    public function findInGroup(int $groupId, string $enrollmentUuid): ?GroupEnrollment
    {
        return $this->model->where('study_group_id', $groupId)
            ->where('uuid', $enrollmentUuid)
            ->with(['student', 'group'])
            ->first();
    }

    public function findByStudentAndGroup(int $studentId, int $groupId): ?GroupEnrollment
    {
        return $this->model->where('study_group_id', $groupId)
            ->where('student_id', $studentId)
            ->first();
    }

    public function countEnrolledInGroup(int $groupId): int
    {
        return $this->model->where('study_group_id', $groupId)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->count();
    }

    protected function applySearchFilter($query, string $term): void
    {
        $query->where(function ($q) use ($term) {
            $q->where('student_name_override', 'like', "%{$term}%")
                ->orWhere('student_phone_override', 'like', "%{$term}%")
                ->orWhere('parent_name_ar', 'like', "%{$term}%")
                ->orWhere('parent_phone', 'like', "%{$term}%")
                ->orWhere('student_barcode', 'like', "%{$term}%")
                ->orWhereHas('student', function ($sq) use ($term) {
                    $sq->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('student_code', 'like', "%{$term}%");
                });
        });
    }
}

