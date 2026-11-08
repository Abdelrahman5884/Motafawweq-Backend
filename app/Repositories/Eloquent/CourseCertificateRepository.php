<?php

namespace App\Repositories\Eloquent;

use App\Models\CourseCertificate;
use App\Repositories\Contracts\CourseCertificateRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CourseCertificateRepository extends BaseRepository implements CourseCertificateRepositoryInterface
{
    public function __construct(CourseCertificate $model)
    {
        parent::__construct($model);
    }

    public function getForTeacher(int $teacherId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('teacher_id', $teacherId)
            ->with(['student', 'course'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $query->where(function ($q) use ($s) {
                $q->where('student_name_ar', 'like', "%{$s}%")
                    ->orWhere('student_name_en', 'like', "%{$s}%")
                    ->orWhere('certificate_number', 'like', "%{$s}%")
                    ->orWhere('student_email', 'like', "%{$s}%");
            });
        }

        if (!empty($filters['course_id']) && $filters['course_id'] !== 'all') {
            $query->where(function ($q) use ($filters) {
                $q->where('course_id', $filters['course_id'])
                    ->orWhere('course_name_ar', 'like', '%' . $filters['course_id'] . '%');
            });
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function findByCertNumber(string $certNumber): ?CourseCertificate
    {
        return $this->model->newQuery()
            ->where('certificate_number', $certNumber)
            ->with(['teacher.teacherProfile', 'course'])
            ->first();
    }

    public function findByUuidForTeacher(string $uuid, int $teacherId): ?CourseCertificate
    {
        return $this->model->newQuery()
            ->where('uuid', $uuid)
            ->where('teacher_id', $teacherId)
            ->first();
    }
}

