<?php

namespace App\Services\V1\Center;

use App\Enums\BillingPeriod;
use App\Enums\CenterPlanTier;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\CenterBranch;
use App\Models\CenterInvoice;
use App\Models\CenterProfile;
use App\Models\CenterRoom;
use App\Models\CenterStaff;
use App\Models\CenterSubscription;
use App\Models\CenterTeacher;
use App\Models\DiscountCoupon;
use App\Models\GroupEnrollment;
use App\Models\PlatformSubscriptionPlan;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CenterBillingService extends BaseService
{
    /**
     * Authoritative server-side pricing matrix (EGP) for the 3 center subscription tiers:
     * Free (0 EGP), Plus (200 / 800 / 1,500 EGP), Pro (400 / 1,600 / 3,000 EGP).
     * Term = 5 months (-20% vs monthly), Annual = 10 academic months (-25% vs monthly).
     */
    public const PRICING = [
        'free' => [
            'monthly' => 0.0,
            'term' => 0.0,
            'annual' => 0.0,
        ],
        'plus' => [
            'monthly' => 200.0,
            'term' => 800.0,
            'annual' => 1500.0,
        ],
        'pro' => [
            'monthly' => 400.0,
            'term' => 1600.0,
            'annual' => 3000.0,
        ],
    ];

    /**
     * Authoritative 3-tier center subscription plans catalog matching frontend centerData.js.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPlansCatalog(): array
    {
        return PlatformSubscriptionPlan::overlayRoleCatalog('center', [
            [
                'id' => 'plan-center-free',
                'tier' => CenterPlanTier::FREE->value,
                'nameAr' => 'باقة السنتر المجانية',
                'nameEn' => 'Center Free',
                'taglineAr' => 'لتجربة تنظيم السنتر الأساسي بسعة محدودة',
                'taglineEn' => 'Try basic center management with starter limits',
                'usersLimitAr' => 'حتى 20 مستخدم نشط فقط (طلاب ومعلمون)',
                'usersLimitEn' => 'Up to 20 active users only (students & teachers)',
                'popular' => false,
                'pricing' => [
                    'monthly' => ['priceEgp' => 0, 'originalPriceEgp' => null, 'periodAr' => 'دائم مجاناً', 'periodEn' => 'Free Forever', 'badgeAr' => 'باقة تجريبية مجانية', 'badgeEn' => 'Starter Free'],
                    'term' => ['priceEgp' => 0, 'originalPriceEgp' => null, 'periodAr' => 'دائم مجاناً', 'periodEn' => 'Free Forever', 'badgeAr' => 'باقة تجريبية مجانية', 'badgeEn' => 'Starter Free'],
                    'annual' => ['priceEgp' => 0, 'originalPriceEgp' => null, 'periodAr' => 'دائم مجاناً', 'periodEn' => 'Free Forever', 'badgeAr' => 'باقة تجريبية مجانية', 'badgeEn' => 'Starter Free'],
                ],
                'featuresAr' => [
                    'سعة استيعاب حتى 20 مستخدم نشط فقط (طلاب ومعلمون)',
                    'فرع واحد رئيسي وقاعة دراسية واحدة فقط',
                    'إدارة حتى معلمين (2) ومجموعتين دراسيتين (2)',
                    'تسجيل الحضور اليدوي الأساسي وإدارة سجل الطلاب',
                    'لوحة تحكم أساسية لمتابعة المجموعات',
                ],
                'featuresEn' => [
                    'Up to 20 active users max (students & teachers)',
                    '1 main branch and 1 classroom only',
                    'Manage up to 2 teachers and 2 study cohorts',
                    'Basic manual student attendance and roster tracking',
                    'Essential center overview dashboard',
                ],
                'limitationsAr' => [
                    'ماسح الباركود و QR للحضور الفوري (USB والكاميرا)',
                    'الجدول الأسبوعي الشامل ومنع تعارض القاعات',
                    'إدارة الموظفين الإداريين والصلاحيات',
                    'سجل العمليات والرقابة والأمان والتقرير التنفيذي',
                    'الماليات وتسويات المعلمين والنسخ الاحتياطي السحابي',
                ],
                'limitationsEn' => [
                    'Instant QR & Barcode attendance scanner (USB & Camera)',
                    'Weekly master schedule & conflict detection',
                    'Administrative staff accounts & permissions',
                    'Security audit logs & executive performance reports',
                    'Financials ERP, settlements & cloud backups',
                ],
            ],
            [
                'id' => 'plan-center-plus',
                'tier' => CenterPlanTier::PLUS->value,
                'nameAr' => 'باقة السنتر بلس',
                'nameEn' => 'Center Plus',
                'taglineAr' => 'الأمثل للسناتر النامية: الباركود، الجدول الأسبوعي، والفروع المتعددة',
                'taglineEn' => 'Ideal for growing centers: QR scanner, weekly schedule & multi-branch',
                'usersLimitAr' => 'حتى 500 مستخدم نشط (طلاب ومعلمون وموظفون)',
                'usersLimitEn' => 'Up to 500 active users (students, teachers & staff)',
                'popular' => false,
                'pricing' => [
                    'monthly' => ['priceEgp' => 200, 'originalPriceEgp' => null, 'periodAr' => 'شهرياً (30 يوماً)', 'periodEn' => 'Monthly (30 days)', 'badgeAr' => 'الأنسب للسناتر النامية', 'badgeEn' => 'Best for Growing Centers'],
                    'term' => ['priceEgp' => 800, 'originalPriceEgp' => 1000, 'periodAr' => 'للترم (5 شهور)', 'periodEn' => 'Semester (5 mo)', 'badgeAr' => 'وفر 20% (200 ج.م)', 'badgeEn' => 'Save 20% (200 EGP)'],
                    'annual' => ['priceEgp' => 1500, 'originalPriceEgp' => 2000, 'periodAr' => 'سنة دراسية (10 شهور)', 'periodEn' => 'Full Year (10 mo)', 'badgeAr' => 'وفر 25% (500 ج.م)', 'badgeEn' => 'Save 25% (500 EGP)'],
                ],
                'featuresAr' => [
                    'سعة حتى 500 مستخدم نشط مسجل بالسنتر',
                    'حتى 5 فروع، 8 قاعات دراسية، 12 معلماً، و 20 مجموعة',
                    'إدارة حتى 15 موظفاً إدارياً بصلاحيات مخصصة',
                    'ماسح الباركود و QR الفوري (USB + كاميرا) وبطاقات الطلاب',
                    'الجدول الأسبوعي الشامل وكشف التعارض الزمني الذكي',
                    'نظام الماليات وتسويات نسب الأرباح والمصروفات',
                ],
                'featuresEn' => [
                    'Up to 500 active center users capacity',
                    'Up to 5 branches, 8 classrooms, 12 teachers & 20 cohorts',
                    'Manage up to 15 administrative staff with custom roles',
                    'High-speed QR / Barcode scanner (USB & Camera) & ID cards',
                    'Full weekly master schedule with smart clash detection',
                    'Financials ERP, commission splits & expense tracking',
                ],
                'limitationsAr' => [
                    'سجل العمليات والرقابة والأمان (Audit Log)',
                    'التقرير التنفيذي الشامل لأداء السنتر والفروع',
                    'إشعارات واتساب، الهوية المستقلة، والنسخ الاحتياطي السحابي',
                ],
                'limitationsEn' => [
                    'Security Audit Log trail',
                    'Executive Performance Report',
                    'WhatsApp parent alerts, White-Label & Cloud Backups',
                ],
            ],
            [
                'id' => 'plan-center-pro',
                'tier' => CenterPlanTier::PRO->value,
                'nameAr' => 'باقة السنتر برو',
                'nameEn' => 'Center Pro',
                'taglineAr' => 'كل الأدوات بلا قيود: مستخدمون وفروع مفتوحة + الرقابة والتقرير التنفيذي',
                'taglineEn' => 'Unlimited enterprise suite: unlimited capacity + audit logs & executive reports',
                'usersLimitAr' => 'مستخدمون وطلاب وفروع غير محدودة',
                'usersLimitEn' => 'Unlimited users, students & branches',
                'popular' => true,
                'pricing' => [
                    'monthly' => ['priceEgp' => 400, 'originalPriceEgp' => null, 'periodAr' => 'شهرياً (30 يوماً)', 'periodEn' => 'Monthly (30 days)', 'badgeAr' => 'الأكثر طلباً للسناتر الكبرى', 'badgeEn' => 'Most Popular'],
                    'term' => ['priceEgp' => 1600, 'originalPriceEgp' => 2000, 'periodAr' => 'للترم (5 شهور)', 'periodEn' => 'Semester (5 mo)', 'badgeAr' => 'وفر 20% (400 ج.م)', 'badgeEn' => 'Save 20% (400 EGP)'],
                    'annual' => ['priceEgp' => 3000, 'originalPriceEgp' => 4000, 'periodAr' => 'سنة دراسية (10 شهور)', 'periodEn' => 'Full Year (10 mo)', 'badgeAr' => 'أقوى قيمة • وفر 25%', 'badgeEn' => 'Best Value • Save 25%'],
                ],
                'featuresAr' => [
                    'جميع مزايا باقة السنتر بلس بالكامل بدون أي حدود',
                    'عدد غير محدود من المستخدمين، الطلاب، المعلمين، والموظفين',
                    'عدد غير محدود من الفروع، القاعات الدراسية، والمجموعات',
                    'سجل العمليات والرقابة والأمان الكامل (Audit Log)',
                    'التقرير التنفيذي الشامل لمقارنة أداء الفروع والقاعات',
                    'تنبيهات واتساب، الهوية المخصصة، والنسخ الاحتياطي السحابي',
                ],
                'featuresEn' => [
                    'Everything in Center Plus with zero capacity limits',
                    'Unlimited active users, students, teachers & staff',
                    'Unlimited branches, classrooms & study cohorts',
                    'Full immutable Security Audit Log trail',
                    'Comprehensive Executive Performance Reports',
                    'WhatsApp alerts, White-Label branding & Cloud Backups',
                ],
                'limitationsAr' => [],
                'limitationsEn' => [],
            ],
        ]);
    }

    /**
     * Resolve the center's authoritative active plan tier (auto-expiring past-due paid plans to Free).
     */
    public function resolveActivePlanTier(User $center): CenterPlanTier
    {
        /** @var CenterSubscription|null $active */
        $active = CenterSubscription::where('center_id', $center->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($active) {
            $tier = $active->plan_tier instanceof CenterPlanTier
                ? $active->plan_tier
                : (CenterPlanTier::tryFrom((string) $active->plan_tier) ?? CenterPlanTier::FREE);

            if ($tier !== CenterPlanTier::FREE && $active->renews_at && $active->renews_at->isPast()) {
                $active->update(['status' => 'expired']);
                $this->syncProfilePlanTier($center, CenterPlanTier::FREE);

                return CenterPlanTier::FREE;
            }

            return $tier;
        }

        if (CenterSubscription::where('center_id', $center->id)->exists()) {
            return CenterPlanTier::FREE;
        }

        $profileTier = $center->relationLoaded('centerProfile') && $center->centerProfile
            ? $center->centerProfile->plan_tier
            : DB::table('center_profiles')->where('user_id', $center->id)->value('plan_tier');

        if ($profileTier instanceof CenterPlanTier) {
            return $profileTier;
        }

        return CenterPlanTier::tryFrom((string) $profileTier) ?? CenterPlanTier::FREE;
    }

    /**
     * Return live quota consumption counts for the center.
     *
     * @return array<string, int>
     */
    public function getUsageStats(User $center): array
    {
        $branchesCount = CenterBranch::where('center_id', $center->id)->count();
        $roomsCount = CenterRoom::where('center_id', $center->id)->count();
        $groupsCount = StudyGroup::where('center_id', $center->id)->where('status', 'active')->count();
        $teachersCount = CenterTeacher::where('center_id', $center->id)->count();
        $staffCount = CenterStaff::where('center_id', $center->id)->count();

        $studentsCount = (int) GroupEnrollment::query()
            ->join('study_groups', 'study_groups.id', '=', 'group_enrollments.study_group_id')
            ->where('study_groups.center_id', $center->id)
            ->whereNull('study_groups.deleted_at')
            ->whereNull('group_enrollments.deleted_at')
            ->where('group_enrollments.status', EnrollmentStatus::ENROLLED->value)
            ->distinct('group_enrollments.student_id')
            ->count('group_enrollments.student_id');

        $activeUsersCount = $studentsCount + $teachersCount + $staffCount;

        return [
            'branchesCount' => (int) $branchesCount,
            'roomsCount' => (int) $roomsCount,
            'groupsCount' => (int) $groupsCount,
            'teachersCount' => (int) $teachersCount,
            'staffCount' => (int) $staffCount,
            'studentsCount' => $studentsCount,
            'activeUsersCount' => $activeUsersCount,
        ];
    }

    /**
     * Enforce center user capacity quota when adding students, teachers, or staff.
     *
     * @throws ValidationException
     */
    public function assertUsersQuotaAvailable(User $center, int $additionalUsers = 1): void
    {
        $tier = $this->resolveActivePlanTier($center);
        $maxUsers = $tier->maxUsers();
        if ($maxUsers === null) {
            return;
        }

        $usage = $this->getUsageStats($center);
        if (($usage['activeUsersCount'] + $additionalUsers) > $maxUsers) {
            throw ValidationException::withMessages([
                'quota' => [__('messages.center.quota_users_exceeded', [
                    'max' => $maxUsers,
                    'plan' => $tier->label(),
                ])],
            ]);
        }
    }

    /**
     * Resolve center's active subscription summary without ever fabricating a paid plan.
     *
     * @return array<string, mixed>
     */
    public function getActiveSubscription(User $center): array
    {
        $tier = $this->resolveActivePlanTier($center);

        /** @var CenterSubscription|null $active */
        $active = CenterSubscription::where('center_id', $center->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        $isFree = $tier === CenterPlanTier::FREE;
        $period = ($active && $active->billing_period instanceof BillingPeriod)
            ? $active->billing_period
            : BillingPeriod::MONTHLY;

        $daysRemaining = (! $isFree && $active?->renews_at)
            ? max(0, (int) ceil(Carbon::now()->diffInHours($active->renews_at, false) / 24))
            : null;

        $highlightsAr = match ($tier) {
            CenterPlanTier::PRO => ['سعة غير محدودة بالكامل', 'سجل العمليات والتقرير التنفيذي', 'ماسح الباركود والجدول الشامل'],
            CenterPlanTier::PLUS => ['حتى 500 مستخدم نشط', 'ماسح الباركود والجدول الشامل', 'إدارة الموظفين والفروع (5)'],
            default => ['حتى 20 مستخدم نشط', 'فرع واحد وقاعة واحدة', 'حضور يدوي ومجموعتان'],
        };

        $highlightsEn = match ($tier) {
            CenterPlanTier::PRO => ['Unlimited Capacity', 'Audit Logs & Executive Report', 'QR Scanner & Master Schedule'],
            CenterPlanTier::PLUS => ['Up to 500 Active Users', 'QR Scanner & Master Schedule', 'Staff & Multi-Branch (5)'],
            default => ['Up to 20 Active Users', '1 Branch & 1 Room', 'Manual Attendance & 2 Cohorts'],
        };

        return [
            'id' => $active?->uuid,
            'planId' => 'plan-center-'.$tier->value,
            'planTier' => $tier->value,
            'planNameAr' => $active?->plan_name_ar ?: $tier->labelAr(),
            'planNameEn' => $active?->plan_name_en ?: $tier->labelEn(),
            'billingPeriod' => $period->value,
            'status' => $active?->status ?? 'active',
            'isFree' => $isFree,
            'canCancel' => ! $isFree,
            'mustCancelToSwitch' => ! $isFree,
            'startsAt' => $active?->starts_at?->toDateString() ?? $center->created_at?->toDateString(),
            'renewalDate' => $isFree ? null : $active?->renews_at?->toDateString(),
            'daysRemaining' => $daysRemaining,
            'amountEgp' => $isFree ? 0.0 : (float) ($active?->amount_egp ?? self::PRICING[$tier->value]['monthly']),
            'highlightsAr' => $highlightsAr,
            'highlightsEn' => $highlightsEn,
            'unlockedFeatures' => $tier->unlockedFeatures(),
            'quotas' => $tier->quotas(),
        ];
    }

    /**
     * Get full billing overview for the center.
     *
     * @return array<string, mixed>
     */
    public function getBillingSummary(User $center): array
    {
        $activeSub = $this->getActiveSubscription($center);
        $usage = $this->getUsageStats($center);
        $plans = $this->getPlansCatalog();

        $invoices = CenterInvoice::where('center_id', $center->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (CenterInvoice $inv) {
                $methodValue = $inv->payment_method?->value ?? 'instapay';
                $methodAr = $inv->payment_method?->label() ?? 'إنستاباي (InstaPay)';
                $statusValue = $inv->status?->value ?? 'paid';
                $amountNum = (float) $inv->final_amount_egp;

                return [
                    'id' => $inv->invoice_number,
                    'uuid' => $inv->uuid,
                    'invoiceNumber' => $inv->invoice_number,
                    'date' => ($inv->paid_at ?? $inv->created_at)?->format('Y-m-d'),
                    'descriptionAr' => $inv->description_ar,
                    'descriptionEn' => $inv->description_en ?: $inv->description_ar,
                    'baseAmountEgp' => (float) $inv->base_amount_egp,
                    'discountAmountEgp' => (float) $inv->discount_amount_egp,
                    'finalAmountEgp' => $amountNum,
                    'amount' => "{$amountNum} ج.م",
                    'amountEn' => "{$amountNum} EGP",
                    'couponCode' => $inv->coupon_code,
                    'paymentMethod' => $methodValue,
                    'methodAr' => $methodAr,
                    'methodEn' => ucfirst($methodValue),
                    'status' => $statusValue,
                    'statusLabelAr' => $inv->status?->label() ?? 'مدفوع',
                    'paidAt' => $inv->paid_at?->format('Y-m-d H:i') ?? $inv->created_at?->format('Y-m-d H:i'),
                ];
            })
            ->values()
            ->all();

        return [
            'activeSub' => $activeSub,
            'plans' => $plans,
            'pricingMatrix' => self::PRICING,
            'usage' => $usage,
            'invoices' => $invoices,
        ];
    }

    /**
     * Validate a coupon code against database coupons and default center promotional codes.
     */
    public function validateCoupon(string $code, float $orderAmount = 0.0): array
    {
        $clean = strtoupper(trim($code));

        if ($clean === '') {
            return [
                'isValid' => false,
                'messageAr' => 'يرجى إدخال كود الخصم',
                'discountPercent' => 0,
                'discountAmount' => 0.0,
                'finalAmountEgp' => $orderAmount,
            ];
        }

        $coupon = DiscountCoupon::where('code', $clean)->first();
        if ($coupon) {
            if (! $coupon->isValid($orderAmount)) {
                return [
                    'isValid' => false,
                    'messageAr' => 'كود الخصم غير صالح أو منتهي الصلاحية أو تجاوز الحد الأقصى للاستخدام',
                    'discountPercent' => 0,
                    'discountAmount' => 0.0,
                    'finalAmountEgp' => $orderAmount,
                ];
            }

            $discountAmt = $coupon->calculateDiscount($orderAmount);
            $finalAmt = max(0.0, round($orderAmount - $discountAmt, 2));

            return [
                'isValid' => true,
                'code' => $coupon->code,
                'couponCode' => $coupon->code,
                'discountPercent' => (int) $coupon->discount_percent,
                'discountAmount' => $discountAmt,
                'finalAmountEgp' => $finalAmt,
                'messageAr' => "تم تطبيق كود الخصم بنجاح ({$coupon->discount_percent}%)",
            ];
        }

        // Promotional platform center coupons matching CenterBillingView.jsx
        $knownCoupons = ['CENTER2026', 'MOTAFAWWEQ2026'];
        if (in_array($clean, $knownCoupons, true)) {
            $discountPct = 20;
            $discountAmt = round($orderAmount * 0.20, 2);
            $finalAmt = max(0.0, round($orderAmount - $discountAmt, 2));

            return [
                'isValid' => true,
                'code' => $clean,
                'couponCode' => $clean,
                'discountPercent' => $discountPct,
                'discountAmount' => $discountAmt,
                'finalAmountEgp' => $finalAmt,
                'messageAr' => "تم تطبيق خصم {$discountPct}% بنجاح!",
            ];
        }

        return [
            'isValid' => false,
            'messageAr' => 'كود الخصم غير صحيح. جرب كود: CENTER2026',
            'discountPercent' => 0,
            'discountAmount' => 0.0,
            'finalAmountEgp' => $orderAmount,
        ];
    }

    /**
     * Activate a paid center subscription atomically with server-side authoritative pricing.
     * STRICT RULE: A center with an active paid subscription MUST cancel it before switching plans.
     */
    public function subscribeOrUpgrade(User $center, array $data): array
    {
        $rawTier = strtolower(trim((string) ($data['plan_tier'] ?? $data['planTier'] ?? '')));
        // Accept frontend plan IDs seamlessly (e.g. 'plan-center-plus' -> 'plus')
        if (str_starts_with($rawTier, 'plan-center-')) {
            $rawTier = substr($rawTier, strlen('plan-center-'));
        }

        $tier = CenterPlanTier::tryFrom($rawTier);
        if (! $tier) {
            throw ValidationException::withMessages([
                'plan_tier' => ['الباقة المحددة غير صالحة.'],
            ]);
        }

        if ($tier === CenterPlanTier::FREE) {
            throw ValidationException::withMessages([
                'plan_tier' => [__('messages.billing.cannot_subscribe_free')],
            ]);
        }

        $currentTier = $this->resolveActivePlanTier($center);
        if ($currentTier !== CenterPlanTier::FREE) {
            $isEn = app()->getLocale() === 'en';
            $currentLabel = $isEn ? $currentTier->labelEn() : $currentTier->labelAr();

            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => __('messages.billing.must_cancel_first', ['plan' => $currentLabel]),
                'error_code' => 'ACTIVE_SUBSCRIPTION_EXISTS',
                'current_plan' => $currentTier->value,
                'current_plan_name_ar' => $currentTier->labelAr(),
                'current_plan_name_en' => $currentTier->labelEn(),
            ], Response::HTTP_CONFLICT));
        }

        $rawPeriod = strtolower(trim((string) ($data['billing_period'] ?? $data['billingPeriod'] ?? 'monthly')));
        $period = BillingPeriod::tryFrom($rawPeriod) ?? BillingPeriod::MONTHLY;

        $basePrice = (float) (self::PRICING[$tier->value][$period->value] ?? 200.0);
        $paymentMethodRaw = strtolower(trim((string) ($data['payment_method'] ?? 'instapay')));
        $paymentMethod = PaymentMethod::tryFrom($paymentMethodRaw) ?? PaymentMethod::INSTAPAY;

        $senderRefParts = array_filter([
            ! empty($data['sender_phone']) ? trim(strip_tags((string) $data['sender_phone'])) : null,
            ! empty($data['sender_ipa']) ? trim(strip_tags((string) $data['sender_ipa'])) : null,
            ! empty($data['transaction_ref']) ? trim(strip_tags((string) $data['transaction_ref'])) : null,
        ]);
        $senderReference = ! empty($senderRefParts) ? implode(' | ', $senderRefParts) : null;

        $periodLabelAr = match ($period) {
            BillingPeriod::MONTHLY => 'شهرياً',
            BillingPeriod::TERM => 'نصف سنوي (5 شهور)',
            BillingPeriod::ANNUAL => 'سنة دراسية كاملة (10 شهور)',
        };

        $planNameAr = "{$tier->labelAr()} — {$periodLabelAr}";
        $planNameEn = "{$tier->labelEn()} ({$period->value})";

        $startsAt = Carbon::now();
        $renewsAt = $startsAt->copy()->addDays($period->durationDays());

        /** @var CenterSubscription $subscription */
        /** @var CenterInvoice $invoice */
        [$subscription, $invoice] = $this->transaction(function () use (
            $center,
            $tier,
            $period,
            $basePrice,
            $startsAt,
            $renewsAt,
            $planNameAr,
            $planNameEn,
            $paymentMethod,
            $senderReference,
            $data
        ) {
            $discountAmount = 0.0;
            $appliedCoupon = null;

            if (! empty($data['coupon_code'])) {
                $cleanCode = strtoupper(trim(strip_tags((string) $data['coupon_code'])));
                /** @var DiscountCoupon|null $dbCoupon */
                $dbCoupon = DiscountCoupon::where('code', $cleanCode)->lockForUpdate()->first();

                if ($dbCoupon) {
                    if ($dbCoupon->isValid($basePrice)) {
                        $discountAmount = $dbCoupon->calculateDiscount($basePrice);
                        $appliedCoupon = $dbCoupon->code;
                        $dbCoupon->increment('used_count');
                    }
                } else {
                    $couponResult = $this->validateCoupon($cleanCode, $basePrice);
                    if ($couponResult['isValid']) {
                        $discountAmount = (float) $couponResult['discountAmount'];
                        $appliedCoupon = $couponResult['couponCode'];
                    }
                }
            }

            $finalPrice = max(0.0, round($basePrice - $discountAmount, 2));

            CenterSubscription::where('center_id', $center->id)
                ->where('status', 'active')
                ->update(['status' => 'superseded']);

            $sub = CenterSubscription::create([
                'center_id' => $center->id,
                'plan_tier' => $tier,
                'plan_name_ar' => $planNameAr,
                'plan_name_en' => $planNameEn,
                'billing_period' => $period,
                'amount_egp' => $finalPrice,
                'starts_at' => $startsAt,
                'renews_at' => $renewsAt,
                'status' => 'active',
            ]);

            $this->syncProfilePlanTier($center, $tier, true);

            $inv = CenterInvoice::create([
                'invoice_number' => CenterInvoice::generateUniqueInvoiceNumber(),
                'center_id' => $center->id,
                'subscription_id' => $sub->id,
                'description_ar' => "اشتراك {$planNameAr}",
                'description_en' => "Subscription: {$planNameEn}",
                'base_amount_egp' => $basePrice,
                'discount_amount_egp' => $discountAmount,
                'final_amount_egp' => $finalPrice,
                'coupon_code' => $appliedCoupon,
                'payment_method' => $paymentMethod,
                'sender_reference' => $senderReference,
                'status' => InvoiceStatus::PAID,
                'paid_at' => $startsAt,
            ]);

            return [$sub, $inv];
        });

        $this->logSecurityEvent(
            'center.plan_subscribed',
            [
                'center_id' => $center->id,
                'tier' => $tier->value,
                'period' => $period->value,
                'invoice_number' => $invoice->invoice_number,
                'final_price' => $invoice->final_amount_egp,
                'payment_method' => $paymentMethod->value,
                'coupon_code' => $invoice->coupon_code,
            ],
            'info',
            $subscription
        );

        return [
            'subscription' => $subscription,
            'invoice' => $invoice,
            'activeSub' => $this->getActiveSubscription($center),
            'usage' => $this->getUsageStats($center),
            'daysRemaining' => $period->durationDays(),
            'messageAr' => __('messages.billing.subscribed_successfully'),
        ];
    }

    /**
     * Cancel the center's active paid subscription and revert their account to the Free tier.
     */
    public function cancelSubscription(User $center): array
    {
        $currentTier = $this->resolveActivePlanTier($center);

        if ($currentTier === CenterPlanTier::FREE) {
            throw ValidationException::withMessages([
                'subscription' => [__('messages.billing.no_active_paid_subscription')],
            ]);
        }

        $cancelledSub = $this->transaction(function () use ($center) {
            /** @var CenterSubscription|null $active */
            $active = CenterSubscription::where('center_id', $center->id)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            CenterSubscription::where('center_id', $center->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => Carbon::now(),
                ]);

            $this->syncProfilePlanTier($center, CenterPlanTier::FREE, true);

            return $active;
        });

        $this->logSecurityEvent(
            'center.plan_cancelled',
            [
                'center_id' => $center->id,
                'previous_plan' => $currentTier->value,
                'subscription_uuid' => $cancelledSub?->uuid,
            ],
            'info',
            $cancelledSub
        );

        return [
            'activeSub' => $this->getActiveSubscription($center),
            'usage' => $this->getUsageStats($center),
            'previousPlan' => $currentTier->value,
            'messageAr' => __('messages.billing.cancelled_successfully'),
        ];
    }

    /**
     * Ensure CenterProfile exists for this user and keep plan_tier synchronized.
     */
    public function syncProfilePlanTier(User $center, CenterPlanTier $tier, bool $createIfMissing = false): void
    {
        if ($createIfMissing) {
            $profile = CenterProfile::firstOrCreate(
                ['user_id' => $center->id],
                [
                    'name_ar' => $center->name ?: 'السنتر التعليمي',
                    'name_en' => $center->name ?: 'Educational Center',
                    'phone' => $center->phone,
                ]
            );
            $profile->forceFill(['plan_tier' => $tier])->save();
            $center->setRelation('centerProfile', $profile);

            return;
        }

        CenterProfile::where('user_id', $center->id)->update([
            'plan_tier' => $tier->value,
        ]);

        if ($center->relationLoaded('centerProfile') && $center->centerProfile) {
            $center->centerProfile->forceFill(['plan_tier' => $tier]);
        }
    }
}
