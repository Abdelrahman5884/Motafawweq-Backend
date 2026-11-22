<?php

namespace App\Repositories\Eloquent;

use App\Enums\CertificateStatus;
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
        $safePerPage = min(100, max(1, $perPage));

        $query = $this->model->newQuery()
            ->where('teacher_id', $teacherId)
            ->with(['student', 'course', 'teacher.teacherProfile'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $query->where(function ($q) use ($s) {
                $q->where('student_name_ar', 'like', "%{$s}%")
                    ->orWhere('student_name_en', 'like', "%{$s}%")
                    ->orWhere('certificate_number', 'like', "%{$s}%")
                    ->orWhere('student_email', 'like', "%{$s}%")
                    ->orWhere('student_phone', 'like', "%{$s}%")
                    ->orWhere('course_name_ar', 'like', "%{$s}%")
                    ->orWhere('honors_title_ar', 'like', "%{$s}%");
            });
        }

        if (! empty($filters['course_id']) && $filters['course_id'] !== 'all') {
            $courseFilter = trim((string) $filters['course_id']);
            $query->where(function ($q) use ($courseFilter) {
                if (is_numeric($courseFilter)) {
                    $q->where('course_id', (int) $courseFilter);
                }
                $q->orWhereHas('course', fn ($c) => $c->where('uuid', $courseFilter))
                    ->orWhere('course_name_ar', 'like', "%{$courseFilter}%");
            });
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($safePerPage);
    }

    public function getTeacherStats(int $teacherId): array
    {
        $row = $this->model->newQuery()
            ->where('teacher_id', $teacherId)
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as verified_count', [CertificateStatus::VERIFIED->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as revoked_count', [CertificateStatus::REVOKED->value])
            ->selectRaw('AVG(CASE WHEN status = ? THEN grade_percent ELSE NULL END) as avg_grade', [CertificateStatus::VERIFIED->value])
            ->first();

        return [
            'total' => (int) ($row?->total_count ?? 0),
            'verifiedCount' => (int) ($row?->verified_count ?? 0),
            'revokedCount' => (int) ($row?->revoked_count ?? 0),
            'averageGrade' => $row?->avg_grade !== null ? round((float) $row->avg_grade, 1) : 0.0,
        ];
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
            ->with(['student', 'course', 'teacher.teacherProfile'])
            ->first();
    }
}
