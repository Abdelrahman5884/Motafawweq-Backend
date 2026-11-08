<?php

namespace App\Services\V1\Teacher;

use App\Enums\DirectPaymentStatus;
use App\Enums\EnrollmentStatus;
use App\Models\GroupEnrollment;
use App\Models\StudentDirectPayment;
use App\Models\User;
use App\Repositories\Contracts\StudentDirectPaymentRepositoryInterface;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class TeacherFinancialService
{
    use HasSecurityAudit;

    public function __construct(
        protected StudentDirectPaymentRepositoryInterface $paymentRepository
    ) {}

    public function getFinancialOverview(User $teacher, array $filters = [], int $perPage = 25): array
    {
        $kpis = $this->paymentRepository->getTeacherFinancialKPIs($teacher->id);
        $paginator = $this->paymentRepository->getForTeacher($teacher->id, $filters, $perPage);

        return [
            'earnings' => [
                'totalRevenueEgp'        => $kpis['totalRevenueEgp'],
                'confirmedStudentsCount' => $kpis['confirmedStudentsCount'],
                'activeCoursesCount'     => $kpis['activeCoursesCount'],
            ],
            'transactions' => $paginator,
        ];
    }

    public function confirmDirectPayment(User $teacher, string $uuid): StudentDirectPayment
    {
        /** @var StudentDirectPayment|null $payment */
        $payment = $this->paymentRepository->findByUuidForTeacher($uuid, $teacher->id);

        if (!$payment) {
            $this->logSecurityEvent(
                'teacher.idor_attempt_blocked',
                [
                    'target_type' => 'StudentDirectPayment',
                    'target_uuid' => $uuid,
                    'actor_id'    => $teacher->id,
                    'action'      => 'confirm',
                ],
                'warning'
            );

            throw (new ModelNotFoundException())->setModel(StudentDirectPayment::class, [$uuid]);
        }

        DB::transaction(function () use ($payment, $teacher) {
            $payment->update([
                'status'       => DirectPaymentStatus::CONFIRMED,
                'confirmed_at' => now(),
            ]);

            // Auto-enroll or activate student in study group if linked
            if ($payment->study_group_id && $payment->student_id) {
                GroupEnrollment::updateOrCreate(
                    [
                        'study_group_id' => $payment->study_group_id,
                        'student_id'     => $payment->student_id,
                    ],
                    [
                        'teacher_id'     => $teacher->id,
                        'status'         => EnrollmentStatus::ENROLLED,
                        'enrolled_at'    => now(),
                    ]
                );
            }
        });

        $this->logSecurityEvent(
            'teacher.direct_payment_confirmed',
            [
                'teacher_id'    => $teacher->id,
                'payment_uuid'  => $payment->uuid,
                'reference_num' => $payment->reference_num,
                'amount_egp'    => $payment->amount_egp,
                'student_id'    => $payment->student_id,
            ],
            'info',
            $payment
        );

        return $payment->fresh(['student', 'course', 'studyGroup']);
    }

    public function rejectDirectPayment(User $teacher, string $uuid, ?string $reason = null): StudentDirectPayment
    {
        /** @var StudentDirectPayment|null $payment */
        $payment = $this->paymentRepository->findByUuidForTeacher($uuid, $teacher->id);

        if (!$payment) {
            $this->logSecurityEvent(
                'teacher.idor_attempt_blocked',
                [
                    'target_type' => 'StudentDirectPayment',
                    'target_uuid' => $uuid,
                    'actor_id'    => $teacher->id,
                    'action'      => 'reject',
                ],
                'warning'
            );

            throw (new ModelNotFoundException())->setModel(StudentDirectPayment::class, [$uuid]);
        }

        $payment->update([
            'status'           => DirectPaymentStatus::REJECTED,
            'rejection_reason' => $reason ?: 'تم رفض التحويل لعدم مطابقة بيانات الإيصال أو نقص المبلغ',
        ]);

        $this->logSecurityEvent(
            'teacher.direct_payment_rejected',
            [
                'teacher_id'    => $teacher->id,
                'payment_uuid'  => $payment->uuid,
                'reference_num' => $payment->reference_num,
                'reason'        => $reason,
            ],
            'warning',
            $payment
        );

        return $payment->fresh(['student', 'course', 'studyGroup']);
    }
}

