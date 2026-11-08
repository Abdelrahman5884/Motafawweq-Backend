<?php

namespace App\Repositories\Eloquent;

use App\Enums\DirectPaymentStatus;
use App\Models\Course;
use App\Models\StudentDirectPayment;
use App\Repositories\Contracts\StudentDirectPaymentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StudentDirectPaymentRepository extends BaseRepository implements StudentDirectPaymentRepositoryInterface
{
    public function __construct(StudentDirectPayment $model)
    {
        parent::__construct($model);
    }

    public function getForTeacher(int $teacherId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('teacher_id', $teacherId)
            ->with(['student', 'course', 'studyGroup', 'payoutAccount'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $query->where(function ($q) use ($s) {
                $q->where('student_name_ar', 'like', "%{$s}%")
                    ->orWhere('student_name_en', 'like', "%{$s}%")
                    ->orWhere('course_name_ar', 'like', "%{$s}%")
                    ->orWhere('reference_num', 'like', "%{$s}%")
                    ->orWhere('student_phone', 'like', "%{$s}%");
            });
        }

        if (!empty($filters['method']) && $filters['method'] !== 'all') {
            $query->where('payment_method', $filters['method']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function findByUuidForTeacher(string $uuid, int $teacherId): ?StudentDirectPayment
    {
        return $this->model->newQuery()
            ->where('uuid', $uuid)
            ->where('teacher_id', $teacherId)
            ->with(['student', 'course', 'studyGroup'])
            ->first();
    }

    public function getTeacherFinancialKPIs(int $teacherId): array
    {
        $confirmedPayments = $this->model->newQuery()
            ->where('teacher_id', $teacherId)
            ->where('status', DirectPaymentStatus::CONFIRMED);

        $totalRevenueEgp = (float) $confirmedPayments->sum('amount_egp');
        $confirmedStudentsCount = (int) $confirmedPayments->distinct('student_id')->count('student_id');
        $activeCoursesCount = (int) Course::where('teacher_id', $teacherId)->count();

        return [
            'totalRevenueEgp'        => $totalRevenueEgp,
            'confirmedStudentsCount' => $confirmedStudentsCount,
            'activeCoursesCount'     => $activeCoursesCount,
        ];
    }
}

