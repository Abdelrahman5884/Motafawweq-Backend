<?php

namespace App\Services\V1\Student;

use App\Enums\CourseStatus;
use App\Enums\DirectPaymentStatus;
use App\Enums\PaymentMethod;
use App\Models\Course;
use App\Models\DiscountCoupon;
use App\Models\StudentDirectPayment;
use App\Models\StudyGroup;
use App\Models\TeacherPayoutAccount;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Validation\ValidationException;

class StudentBillingService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    /**
     * Get the student's payment history (invoices) and available teacher payout accounts.
     */
    public function getBillingOverview(User $student): array
    {
        $payments = StudentDirectPayment::query()
            ->where('student_id', $student->id)
            ->with(['teacher:id,name'])
            ->latest('paid_at')
            ->get()
            ->map(fn (StudentDirectPayment $p) => [
                'id' => $p->uuid,
                'referenceNum' => $p->reference_num,
                'courseNameAr' => $p->course_name_ar,
                'teacherName' => $p->teacher?->name,
                'amountEgp' => (float) $p->amount_egp,
                'paymentMethod' => $p->payment_method instanceof PaymentMethod ? $p->payment_method->value : (string) $p->payment_method,
                'status' => $p->status instanceof DirectPaymentStatus ? $p->status->value : (string) $p->status,
                'paidAt' => $p->paid_at?->toIso8601String(),
                'confirmedAt' => $p->confirmed_at?->toIso8601String(),
                'rejectionReason' => $p->rejection_reason,
            ])->values()->all();

        $entitlements = $this->accessService->getEntitlements($student);
        $payoutAccounts = ! empty($entitlements['teacher_ids'])
            ? TeacherPayoutAccount::query()
                ->whereIn('teacher_id', $entitlements['teacher_ids'])
                ->where('is_active', true)
                ->with('teacher:id,name')
                ->orderByDesc('is_default')
                ->get()
                ->map(fn (TeacherPayoutAccount $acc) => [
                    'id' => $acc->uuid,
                    'teacherName' => $acc->teacher?->name,
                    'type' => $acc->type?->value ?? (string) $acc->type,
                    'labelAr' => $acc->label_ar ?: ($acc->type?->defaultLabelAr() ?? ''),
                    'labelEn' => $acc->label_en ?: ($acc->type?->defaultLabelEn() ?? ''),
                    'accountHolder' => $acc->teacher?->name ?? '',
                    'accountNumber' => (string) $acc->account_identifier,
                    'accountIdentifier' => (string) $acc->account_identifier,
                    'isPrimary' => (bool) $acc->is_default,
                    'isDefault' => (bool) $acc->is_default,
                ])->values()->all()
            : [];

        return [
            'payments' => $payments,
            'payoutAccounts' => $payoutAccounts,
        ];
    }

    /**
     * Validate a discount coupon code against server-side rules without mutating usage counters.
     */
    public function validateCoupon(string $rawCode, float $orderAmountEgp = 0.0): array
    {
        $code = strtoupper(trim(strip_tags($rawCode)));
        /** @var DiscountCoupon|null $coupon */
        $coupon = DiscountCoupon::where('code', $code)->first();

        if (! $coupon || ! $coupon->is_active) {
            throw ValidationException::withMessages(['code' => ['كود الخصم غير صالح أو غير مفعل.']]);
        }

        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => ['انتهت صلاحية كود الخصم.']]);
        }

        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            throw ValidationException::withMessages(['code' => ['تم استنفاد الحد الأقصى لاستخدام كود الخصم.']]);
        }

        if ($orderAmountEgp > 0 && $orderAmountEgp < (float) $coupon->min_order_egp) {
            throw ValidationException::withMessages([
                'code' => ["الحد الأدنى لتطبيق هذا الكوبون هو {$coupon->min_order_egp} ج.م."],
            ]);
        }

        $discountAmount = $orderAmountEgp > 0
            ? round($orderAmountEgp * ($coupon->discount_percent / 100), 2)
            : 0.0;

        if ($coupon->max_discount_egp !== null && $discountAmount > (float) $coupon->max_discount_egp) {
            $discountAmount = (float) $coupon->max_discount_egp;
        }

        return [
            'code' => $coupon->code,
            'discountPercent' => (int) $coupon->discount_percent,
            'maxDiscountEgp' => $coupon->max_discount_egp !== null ? (float) $coupon->max_discount_egp : null,
            'discountEgp' => $discountAmount,
            'finalAmountEgp' => max(0.0, round($orderAmountEgp - $discountAmount, 2)),
        ];
    }

    /**
     * Create a student direct payment request.
     * Price is computed strictly on the server from the target StudyGroup (or Course) and atomic coupon redemption.
     */
    public function createDirectPayment(User $student, array $data): StudentDirectPayment
    {
        return $this->transaction(function () use ($student, $data) {
            $group = null;
            $course = null;
            $teacherId = null;
            $basePriceEgp = null;
            $itemTitleAr = '';

            if (! empty($data['group_uuid'])) {
                /** @var StudyGroup|null $group */
                $group = StudyGroup::where('uuid', $data['group_uuid'])->where('status', 'active')->first();
                if (! $group) {
                    throw ValidationException::withMessages(['group_uuid' => ['المجموعة الدراسية غير موجودة.']]);
                }
                $teacherId = (int) $group->teacher_id;
                $course = $group->course;
                $basePriceEgp = (float) $group->price_egp;
                $itemTitleAr = $group->name_ar;
            } elseif (! empty($data['course_uuid'])) {
                /** @var Course|null $course */
                $course = Course::where('uuid', $data['course_uuid'])
                    ->where('status', CourseStatus::PUBLISHED)
                    ->first();
                if (! $course) {
                    throw ValidationException::withMessages(['course_uuid' => ['المقرر غير موجود أو غير متاح للتسجيل حالياً.']]);
                }
                $this->accessService->clearCache($student);
                if ($this->accessService->canAccessCourse($student, $course)) {
                    throw ValidationException::withMessages(['course_uuid' => ['أنت مشترك بالفعل في هذا المقرر ويمكنك تصفح حصصه مباشرة.']]);
                }
                $teacherId = (int) $course->teacher_id;
                $itemTitleAr = $course->title_ar;
                $groupForCourse = StudyGroup::where('course_id', $course->id)->where('status', 'active')->first();
                $basePriceEgp = $course->price_egp !== null ? (float) $course->price_egp : ($groupForCourse ? (float) $groupForCourse->price_egp : 0.0);
            } else {
                throw ValidationException::withMessages(['target' => ['يرجى تحديد المجموعة أو الكورس المراد سداد اشتراكه.']]);
            }

            if ($basePriceEgp < 0) {
                $basePriceEgp = 0.0;
            }

            if ($course && ! $group) {
                $alreadyPending = StudentDirectPayment::where('student_id', $student->id)
                    ->where('course_id', $course->id)
                    ->where('status', DirectPaymentStatus::PENDING)
                    ->first();
                if ($alreadyPending) {
                    return $alreadyPending;
                }
            }

            $payoutAccountId = null;
            if (! empty($data['payout_account_uuid'])) {
                $payoutAcc = TeacherPayoutAccount::where('uuid', $data['payout_account_uuid'])
                    ->where('teacher_id', $teacherId)
                    ->where('is_active', true)
                    ->first();
                if (! $payoutAcc) {
                    throw ValidationException::withMessages(['payout_account_uuid' => ['حساب الدفع المختار غير متاح أو لا ينتمي لهذا المعلم.']]);
                }
                $payoutAccountId = $payoutAcc->id;
            }

            $finalAmount = $basePriceEgp;

            // Atomic coupon redemption with lockForUpdate
            if (! empty($data['coupon_code'])) {
                $code = strtoupper(trim(strip_tags((string) $data['coupon_code'])));
                /** @var DiscountCoupon|null $coupon */
                $coupon = DiscountCoupon::where('code', $code)->lockForUpdate()->first();

                if (! $coupon || ! $coupon->is_active || ($coupon->expires_at && $coupon->expires_at->isPast()) || ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses)) {
                    throw ValidationException::withMessages(['coupon_code' => ['كود الخصم غير صالح أو منتهي الصلاحية.']]);
                }

                if ($basePriceEgp < (float) $coupon->min_order_egp) {
                    throw ValidationException::withMessages(['coupon_code' => ['قيمة الاشتراك أقل من الحد الأدنى لتطبيق الكوبون.']]);
                }

                $discount = round($basePriceEgp * ($coupon->discount_percent / 100), 2);
                if ($coupon->max_discount_egp !== null && $discount > (float) $coupon->max_discount_egp) {
                    $discount = (float) $coupon->max_discount_egp;
                }

                $finalAmount = max(0.0, round($basePriceEgp - $discount, 2));
                $coupon->increment('used_count');
            }

            $method = strtolower(trim((string) ($data['payment_method'] ?? PaymentMethod::VODAFONE->value)));
            if (! in_array($method, array_column(PaymentMethod::cases(), 'value'), true)) {
                $method = PaymentMethod::VODAFONE->value;
            }

            $payment = StudentDirectPayment::create([
                'teacher_id' => $teacherId,
                'student_id' => $student->id,
                'course_id' => $course?->id,
                'study_group_id' => $group?->id,
                'payout_account_id' => $payoutAccountId,
                'amount_egp' => $finalAmount,
                'payment_method' => $method,
                'student_name_ar' => $student->name,
                'student_phone' => $student->phone,
                'course_name_ar' => $itemTitleAr,
                'receipt_image_path' => isset($data['receipt_image_path']) ? trim(strip_tags((string) $data['receipt_image_path'])) : null,
                'status' => DirectPaymentStatus::PENDING,
                'paid_at' => now(),
                'notes' => isset($data['notes']) ? trim(strip_tags((string) $data['notes'])) : null,
            ]);

            $this->logSecurityEvent(
                'student.payment.created',
                [
                    'payment_uuid' => $payment->uuid,
                    'reference_num' => $payment->reference_num,
                    'amount_egp' => $finalAmount,
                    'teacher_id' => $teacherId,
                ],
                'info',
                $payment
            );

            return $payment;
        });
    }
}
