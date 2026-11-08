<?php

namespace App\Services\V1\Teacher;

use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\TeacherPlanTier;
use App\Models\DiscountCoupon;
use App\Models\TeacherInvoice;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\User;
use App\Traits\HasSecurityAudit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TeacherBillingService
{
    use HasSecurityAudit;

    /**
     * Standard pricing matrix matching frontend teacherData.js.
     */
    public const PRICING = [
        'free' => [
            'monthly' => 0.0,
            'term'    => 0.0,
            'annual'  => 0.0,
        ],
        'plus' => [
            'monthly' => 120.0,
            'term'    => 480.0,
            'annual'  => 900.0,
        ],
        'pro' => [
            'monthly' => 220.0,
            'term'    => 880.0,
            'annual'  => 1650.0,
        ],
    ];

    /**
     * Get active plan, plan catalog, and invoice history for the teacher.
     */
    public function getBillingSummary(User $teacher): array
    {
        $profile = $teacher->teacherProfile;
        $tier = $profile?->plan_tier ?? TeacherPlanTier::PLUS;

        /** @var TeacherSubscription|null $activeSub */
        $activeSub = TeacherSubscription::where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->orderByDesc('renews_at')
            ->first();

        if (!$activeSub) {
            $period = BillingPeriod::TERM;
            $renewsAt = Carbon::now()->addDays($period->durationDays());
            $activeSub = TeacherSubscription::create([
                'teacher_id'     => $teacher->id,
                'plan_tier'      => $tier,
                'plan_name_ar'   => 'باقة ' . ($tier === TeacherPlanTier::PRO ? 'المحترف (Pro)' : 'المعلم بلس (Plus)') . ' — الفصل الدراسي الأول',
                'plan_name_en'   => 'Teacher ' . ucfirst($tier->value) . ' — Active Semester',
                'billing_period' => $period,
                'amount_egp'     => self::PRICING[$tier->value]['term'] ?? 480.0,
                'starts_at'      => Carbon::now()->subDays(10),
                'renews_at'      => $renewsAt,
                'status'         => 'active',
            ]);
        }

        $daysRemaining = max(0, (int) Carbon::now()->diffInDays($activeSub->renews_at, false));

        $invoices = TeacherInvoice::where('teacher_id', $teacher->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (TeacherInvoice $inv) {
                return [
                    'id'            => $inv->uuid,
                    'invoiceNumber' => $inv->invoice_number,
                    'descriptionAr' => $inv->description_ar,
                    'descriptionEn' => $inv->description_en,
                    'baseAmountEgp' => $inv->base_amount_egp,
                    'discountEgp'   => $inv->discount_amount_egp,
                    'finalAmountEgp'=> $inv->final_amount_egp,
                    'paymentMethod' => $inv->payment_method?->value ?? 'vodafone',
                    'methodLabelAr' => $inv->payment_method?->label() ?? 'فودافون كاش',
                    'status'        => $inv->status?->value ?? 'paid',
                    'statusLabelAr' => $inv->status?->label() ?? 'مدفوع',
                    'paidAt'        => $inv->paid_at?->format('Y-m-d H:i') ?? $inv->created_at->format('Y-m-d H:i'),
                ];
            });

        return [
            'activeSub' => [
                'planTier'      => $activeSub->plan_tier->value,
                'planNameAr'    => $activeSub->plan_name_ar,
                'planNameEn'    => $activeSub->plan_name_en,
                'billingPeriod' => $activeSub->billing_period->value,
                'status'        => $activeSub->status,
                'renewalDate'   => $activeSub->renews_at->toDateString(),
                'daysRemaining' => $daysRemaining,
                'amountEgp'     => $activeSub->amount_egp,
            ],
            'pricingMatrix' => self::PRICING,
            'invoices'      => $invoices,
        ];
    }

    /**
     * Validate coupon code against database and default promotional codes.
     */
    public function validateCoupon(string $code, float $orderAmount = 0.0): array
    {
        $clean = strtoupper(trim($code));

        if ($clean === '') {
            return [
                'isValid'         => false,
                'messageAr'       => 'يرجى إدخال كود الخصم',
                'discountPercent' => 0,
                'discountAmount'  => 0.0,
            ];
        }

        $coupon = DiscountCoupon::where('code', $clean)->first();

        if ($coupon) {
            if (!$coupon->isValid($orderAmount)) {
                return [
                    'isValid'         => false,
                    'messageAr'       => 'كود الخصم غير صالح أو منتهي الصلاحية أو تجاوز الحد الأقصى للاستخدام',
                    'discountPercent' => 0,
                    'discountAmount'  => 0.0,
                ];
            }

            $discountAmt = $coupon->calculateDiscount($orderAmount);

            return [
                'isValid'         => true,
                'couponCode'      => $coupon->code,
                'discountPercent' => $coupon->discount_percent,
                'discountAmount'  => $discountAmt,
                'messageAr'       => "تم تطبيق كود الخصم بنجاح ({$coupon->discount_percent}%)",
            ];
        }

        // Promotional platform coupons fallback
        $knownCoupons = ['TEACHER2026', 'MOTAFAWWEQ2026', 'SALMA2026'];
        if (in_array($clean, $knownCoupons, true)) {
            $discountPct = 25;
            $discountAmt = round($orderAmount * 0.25, 2);

            return [
                'isValid'         => true,
                'couponCode'      => $clean,
                'discountPercent' => $discountPct,
                'discountAmount'  => $discountAmt,
                'messageAr'       => "تم تطبيق كود الخصم بنجاح ({$discountPct}%)",
            ];
        }

        return [
            'isValid'         => false,
            'messageAr'       => 'كود الخصم غير صحيح. جرب كود: TEACHER2026',
            'discountPercent' => 0,
            'discountAmount'  => 0.0,
        ];
    }

    /**
     * Upgrade or renew teacher plan atomically, generating invoice and setting permissions.
     */
    public function subscribeOrUpgrade(User $teacher, array $data): array
    {
        $rawTier = strtolower(trim((string) ($data['plan_tier'] ?? $data['planTier'] ?? 'plus')));
        $tier = TeacherPlanTier::tryFrom($rawTier);

        if (!$tier) {
            throw new InvalidArgumentException("الباقة المحددة '{$rawTier}' غير صالحة.");
        }

        $rawPeriod = strtolower(trim((string) ($data['billing_period'] ?? $data['billingPeriod'] ?? 'monthly')));
        $period = BillingPeriod::tryFrom($rawPeriod) ?? BillingPeriod::MONTHLY;

        $basePrice = self::PRICING[$tier->value][$period->value] ?? 120.0;
        $discountAmount = 0.0;
        $appliedCoupon = null;

        if (!empty($data['coupon_code'])) {
            $couponResult = $this->validateCoupon((string) $data['coupon_code'], $basePrice);
            if ($couponResult['isValid']) {
                $discountAmount = (float) $couponResult['discountAmount'];
                $appliedCoupon = $couponResult['couponCode'];
            }
        }

        $finalPrice = max(0.0, round($basePrice - $discountAmount, 2));
        $paymentMethodRaw = strtolower(trim((string) ($data['payment_method'] ?? 'vodafone')));
        $paymentMethod = PaymentMethod::tryFrom($paymentMethodRaw) ?? PaymentMethod::VODAFONE;

        $periodLabelAr = match ($period) {
            BillingPeriod::MONTHLY => 'شهرياً',
            BillingPeriod::TERM    => 'نصف سنوي (ترم دراسي)',
            BillingPeriod::ANNUAL  => 'سنة دراسية كاملة',
        };

        $planNameAr = "باقة المعلم {$tier->label()} ({$periodLabelAr})";
        $planNameEn = "Teacher {$tier->value} Plan ({$period->value})";

        $startsAt = Carbon::now();
        $renewsAt = Carbon::now()->addDays($period->durationDays());

        /** @var TeacherSubscription $subscription */
        /** @var TeacherInvoice $invoice */
        [$subscription, $invoice] = DB::transaction(function () use (
            $teacher, $tier, $period, $finalPrice, $basePrice, $discountAmount,
            $startsAt, $renewsAt, $planNameAr, $planNameEn, $paymentMethod, $appliedCoupon
        ) {
            // Deactivate previous active subscriptions
            TeacherSubscription::where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->update(['status' => 'superseded']);

            // Create new active subscription
            $sub = TeacherSubscription::create([
                'teacher_id'     => $teacher->id,
                'plan_tier'      => $tier,
                'plan_name_ar'   => $planNameAr,
                'plan_name_en'   => $planNameEn,
                'billing_period' => $period,
                'amount_egp'     => $finalPrice,
                'starts_at'      => $startsAt,
                'renews_at'      => $renewsAt,
                'status'         => 'active',
            ]);

            // Update Teacher Profile plan_tier
            TeacherProfile::where('user_id', $teacher->id)->update([
                'plan_tier' => $tier->value,
            ]);

            // Create official paid invoice
            $inv = TeacherInvoice::create([
                'invoice_number'      => TeacherInvoice::generateUniqueInvoiceNumber(),
                'teacher_id'          => $teacher->id,
                'subscription_id'     => $sub->id,
                'description_ar'      => "اشتراك {$planNameAr}",
                'description_en'      => "Subscription: {$planNameEn}",
                'base_amount_egp'     => $basePrice,
                'discount_amount_egp' => $discountAmount,
                'final_amount_egp'    => $finalPrice,
                'payment_method'      => $paymentMethod,
                'status'              => InvoiceStatus::PAID,
                'paid_at'             => now(),
            ]);

            // If DB coupon model was used, increment usage
            if ($appliedCoupon) {
                DiscountCoupon::where('code', $appliedCoupon)->increment('used_count');
            }

            return [$sub, $inv];
        });

        $this->logSecurityEvent(
            'teacher.plan_subscribed',
            [
                'teacher_id'     => $teacher->id,
                'tier'           => $tier->value,
                'period'         => $period->value,
                'invoice_number' => $invoice->invoice_number,
                'final_price'    => $finalPrice,
                'payment_method' => $paymentMethod->value,
            ],
            'info',
            $subscription
        );

        return [
            'subscription' => $subscription,
            'invoice'      => $invoice,
            'daysRemaining'=> $period->durationDays(),
            'messageAr'    => 'تم ترقية وتفعيل الباقة بنوجاح وإصدار الفاتورة الرسمية.',
        ];
    }
}

