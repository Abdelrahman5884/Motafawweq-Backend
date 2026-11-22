<?php

namespace App\Services\V1\Teacher;

use App\Enums\BillingPeriod;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\TeacherPlanTier;
use App\Models\Course;
use App\Models\DiscountCoupon;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\StudyGroup;
use App\Models\TeacherInvoice;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\User;
use App\Repositories\Contracts\TeacherProfileRepositoryInterface;
use App\Traits\HasSecurityAudit;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class TeacherBillingService
{
    use HasSecurityAudit;

    /**
     * Authoritative server-side pricing matrix (EGP) for the 3 teacher subscription tiers:
     * Free (0 EGP), Plus (120 / 480 / 900 EGP), Pro (150 / 600 / 1,125 EGP).
     * Term = 5 months (-20% vs monthly), Annual = 10 academic months (-25% vs monthly).
     */
    public const PRICING = [
        'free' => [
            'monthly' => 0.0,
            'term' => 0.0,
            'annual' => 0.0,
        ],
        'plus' => [
            'monthly' => 120.0,
            'term' => 480.0,
            'annual' => 900.0,
        ],
        'pro' => [
            'monthly' => 150.0,
            'term' => 600.0,
            'annual' => 1125.0,
        ],
    ];

    public function __construct(
        protected TeacherProfileRepositoryInterface $profileRepository
    ) {}

    /**
     * Authoritative 3-tier teacher subscription plans catalog (Free, Plus, Pro).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPlansCatalog(): array
    {
        return [
            [
                'id' => 'teacher-plan-free',
                'tier' => TeacherPlanTier::FREE->value,
                'nameAr' => 'الباقة المجانية للمعلم',
                'nameEn' => 'Teacher Free',
                'subtitleAr' => 'ابدأ تجربتك على المنصة بإنشاء كورس واحد ومجموعة واحدة مجاناً.',
                'subtitleEn' => 'Start on the platform with 1 course, up to 3 lessons, and 1 study group for free.',
                'popular' => false,
                'pricing' => [
                    'monthly' => [
                        'priceEgp' => 0,
                        'originalPriceEgp' => null,
                        'periodAr' => 'مجاناً مدى الحياة',
                        'periodEn' => 'Forever Free',
                        'badgeAr' => 'مجانية 100%',
                        'badgeEn' => '100% Free',
                    ],
                    'term' => [
                        'priceEgp' => 0,
                        'originalPriceEgp' => null,
                        'periodAr' => 'مجاناً مدى الحياة',
                        'periodEn' => 'Forever Free',
                        'badgeAr' => 'مجانية 100%',
                        'badgeEn' => '100% Free',
                    ],
                    'annual' => [
                        'priceEgp' => 0,
                        'originalPriceEgp' => null,
                        'periodAr' => 'مجاناً مدى الحياة',
                        'periodEn' => 'Forever Free',
                        'badgeAr' => 'مجانية 100%',
                        'badgeEn' => '100% Free',
                    ],
                ],
                'featuresAr' => [
                    'إنشاء كورس دراسي واحد (1 كورس فقط)',
                    'رفع حتى 3 حصص دراسية (صوت أو فيديو أو PDF) بدون AI',
                    'إنشاء مجموعة دراسية واحدة فقط بحد أقصى 50 طالب',
                    'إدارة قائمة طلاب المجموعة وتسجيل الحضور والغياب بالباركود',
                ],
                'featuresEn' => [
                    'Create 1 active course',
                    'Upload up to 3 lessons (Audio, Video, or PDF) without AI',
                    'Create 1 study group with up to 50 students max',
                    'Student roster management and QR attendance tracking',
                ],
                'limitationsAr' => [
                    'الجدول الأسبوعي المنظّم للمجموعات والحصص',
                    'الامتحانات الإلكترونية والواجبات ودوري الكورس والشهادات والماليات',
                    'كافة أدوات الذكاء الاصطناعي (AI) على الحصص',
                ],
                'limitationsEn' => [
                    'Weekly timetable organizer for groups & sessions',
                    'Exams, Homework grading, Course Leagues, Certificates & Financials',
                    'All lesson AI capabilities (Transcript, Mind Map & Smart Chapters)',
                ],
            ],
            [
                'id' => 'teacher-plan-plus',
                'tier' => TeacherPlanTier::PLUS->value,
                'nameAr' => 'باقة المعلم بلس (Plus)',
                'nameEn' => 'Teacher Plus',
                'subtitleAr' => 'كل أدوات إدارة الكورسات والمجموعات والجدول والامتحانات والماليات بلا قيود — ماعدا AI.',
                'subtitleEn' => 'Full teaching suite: up to 12 groups, unlimited courses & lessons, weekly schedule, exams & financials (without AI).',
                'popular' => true,
                'pricing' => [
                    'monthly' => [
                        'priceEgp' => 120,
                        'originalPriceEgp' => null,
                        'periodAr' => 'اشتراك شهري (30 يوماً)',
                        'periodEn' => 'Monthly (30 days)',
                        'badgeAr' => 'الأكثر اختياراً للمعلمين',
                        'badgeEn' => 'Most Popular',
                    ],
                    'term' => [
                        'priceEgp' => 480,
                        'originalPriceEgp' => 600,
                        'periodAr' => 'نصف سنة دراسية (5 شهور)',
                        'periodEn' => 'Semester Pass (5 Months)',
                        'badgeAr' => 'وفر 20%',
                        'badgeEn' => 'Save 20%',
                    ],
                    'annual' => [
                        'priceEgp' => 900,
                        'originalPriceEgp' => 1200,
                        'periodAr' => 'سنة دراسية كاملة (10 شهور)',
                        'periodEn' => 'Full Academic Year',
                        'badgeAr' => 'وفر 25%',
                        'badgeEn' => 'Save 25%',
                    ],
                ],
                'featuresAr' => [
                    'إنشاء حتى 12 مجموعة دراسية بعدد طلاب مفتوح في كل مجموعة',
                    'إنشاء عدد غير محدود من الكورسات ورفع عدد غير محدود من الحصص',
                    'الجدول الأسبوعي الكامل لتنظيم مواعيد المجموعات والملاحظات بدون تعارض',
                    'الامتحانات الإلكترونية وبنك الأسئلة + الواجبات والتصحيح الكامل',
                    'دوري الكورس التنافسي، الشهادات المعتمدة بكود QR، وإدارة المدفوعات والماليات',
                    'العلامة المائية الديناميكية لحماية الحصص وتنبيهات ولي الأمر',
                ],
                'featuresEn' => [
                    'Create up to 12 study groups with unlimited students',
                    'Unlimited courses and unlimited lesson uploads',
                    'Full Weekly Schedule organizer with conflict detection & notes',
                    'Online Exams, Question Bank & Homework grading workflows',
                    'Course Leagues, QR-verified Certificates & Direct Financials',
                    'Dynamic DRM watermark protection & parent alerts',
                ],
                'limitationsAr' => [
                    'أدوات الذكاء الاصطناعي (AI) على الحصص (التفريغ النصي، خريطة المفاهيم، والمعالجة الذكية)',
                ],
                'limitationsEn' => [
                    'Lesson AI capabilities (AI Transcript, Concept Mind Map & AI Processing)',
                ],
            ],
            [
                'id' => 'teacher-plan-pro',
                'tier' => TeacherPlanTier::PRO->value,
                'nameAr' => 'باقة المعلم برو (Pro)',
                'nameEn' => 'Teacher Pro',
                'subtitleAr' => 'كل مزايا بلس + مجموعات غير محدودة + كافة أدوات الذكاء الاصطناعي (AI) على الحصص.',
                'subtitleEn' => 'Everything in Plus + unlimited study groups + the complete AI lesson studio & workspace.',
                'popular' => false,
                'pricing' => [
                    'monthly' => [
                        'priceEgp' => 150,
                        'originalPriceEgp' => null,
                        'periodAr' => 'اشتراك شهري (30 يوماً)',
                        'periodEn' => 'Monthly (30 days)',
                        'badgeAr' => 'شاملة AI ومجموعات مفتوحة',
                        'badgeEn' => 'All AI • Unlimited Groups',
                    ],
                    'term' => [
                        'priceEgp' => 600,
                        'originalPriceEgp' => 750,
                        'periodAr' => 'نصف سنة دراسية (5 شهور)',
                        'periodEn' => 'Semester Pass (5 Months)',
                        'badgeAr' => 'شاملة AI • وفر 20%',
                        'badgeEn' => 'AI Included • Save 20%',
                    ],
                    'annual' => [
                        'priceEgp' => 1125,
                        'originalPriceEgp' => 1500,
                        'periodAr' => 'سنة دراسية كاملة (10 شهور)',
                        'periodEn' => 'Full Academic Year',
                        'badgeAr' => 'أقوى قيمة • وفر 25%',
                        'badgeEn' => 'Best Value • Save 25%',
                    ],
                ],
                'featuresAr' => [
                    'كافة مزايا باقة المعلم بلس بدون أي قيود',
                    'إنشاء عدد غير محدود من المجموعات الدراسية (أكثر من 12 مجموعة)',
                    'التفريغ النصي الذكي المتزامن للحصص بالذكاء الاصطناعي (AI Speech-to-Text)',
                    'توليد خرائط المفاهيم التفاعلية (NotebookLM Concept Graph) تلقائياً لكل حصة',
                    'فهرسة الفصول والمفاهيم الذكية وشاشة معالجة الحصص بالذكاء الاصطناعي',
                ],
                'featuresEn' => [
                    'Everything in Teacher Plus with zero limits',
                    'Unlimited study groups (beyond 12 groups)',
                    'AI Synchronized Lesson Transcript (Speech-to-Text)',
                    'AI Interactive Concept Mind Maps (NotebookLM Concept Graph)',
                    'AI Smart Chapter Indexing & AI Lesson Processing Studio',
                ],
                'limitationsAr' => [],
                'limitationsEn' => [],
            ],
        ];
    }

    /**
     * Resolve the teacher's authoritative active plan tier (auto-expiring past-due paid plans to Free).
     */
    public function resolveActivePlanTier(User $teacher): TeacherPlanTier
    {
        /** @var TeacherSubscription|null $active */
        $active = TeacherSubscription::where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($active) {
            $tier = $active->plan_tier instanceof TeacherPlanTier
                ? $active->plan_tier
                : (TeacherPlanTier::tryFrom((string) $active->plan_tier) ?? TeacherPlanTier::FREE);

            if ($tier !== TeacherPlanTier::FREE && $active->renews_at && $active->renews_at->isPast()) {
                $active->update(['status' => 'expired']);
                $this->syncProfilePlanTier($teacher, TeacherPlanTier::FREE);

                return TeacherPlanTier::FREE;
            }

            return $tier;
        }

        // If the teacher had any prior paid/cancelled/expired subscription and none is active now, they are Free.
        $hasSubscriptionHistory = TeacherSubscription::where('teacher_id', $teacher->id)->exists();
        if ($hasSubscriptionHistory) {
            return TeacherPlanTier::FREE;
        }

        // Fallback to explicitly persisted teacher_profiles.plan_tier (which defaults to FREE for new teachers).
        $profileTier = $teacher->relationLoaded('teacherProfile') && $teacher->teacherProfile
            ? $teacher->teacherProfile->plan_tier
            : DB::table('teacher_profiles')->where('user_id', $teacher->id)->value('plan_tier');

        if ($profileTier instanceof TeacherPlanTier) {
            return $profileTier;
        }

        return TeacherPlanTier::tryFrom((string) $profileTier) ?? TeacherPlanTier::FREE;
    }

    /**
     * Return live quota consumption counts for the teacher.
     *
     * @return array<string, int>
     */
    public function getUsageStats(User $teacher): array
    {
        $coursesCount = Course::where('teacher_id', $teacher->id)->count();
        $lessonsCount = Lesson::where('teacher_id', $teacher->id)->count();
        $groupsCount = StudyGroup::where('teacher_id', $teacher->id)->count();
        $studentsCount = GroupEnrollment::query()
            ->join('study_groups', 'study_groups.id', '=', 'group_enrollments.study_group_id')
            ->where('study_groups.teacher_id', $teacher->id)
            ->whereNull('study_groups.deleted_at')
            ->whereNull('group_enrollments.deleted_at')
            ->where('group_enrollments.status', EnrollmentStatus::ENROLLED->value)
            ->count();

        return [
            'coursesCount' => (int) $coursesCount,
            'lessonsCount' => (int) $lessonsCount,
            'groupsCount' => (int) $groupsCount,
            'studentsCount' => (int) $studentsCount,
        ];
    }

    /**
     * Resolve teacher's active subscription summary without ever fabricating a paid plan.
     *
     * @return array<string, mixed>
     */
    public function getActiveSubscription(User $teacher): array
    {
        $tier = $this->resolveActivePlanTier($teacher);

        /** @var TeacherSubscription|null $active */
        $active = TeacherSubscription::where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        $isFree = $tier === TeacherPlanTier::FREE;
        $period = ($active && $active->billing_period instanceof BillingPeriod)
            ? $active->billing_period
            : BillingPeriod::MONTHLY;

        $daysRemaining = (! $isFree && $active?->renews_at)
            ? max(0, (int) ceil(Carbon::now()->diffInHours($active->renews_at, false) / 24))
            : null;

        $highlightsAr = match ($tier) {
            TeacherPlanTier::PRO => [
                'مجموعات وكورسات وحصص غير محدودة',
                'الجدول الأسبوعي + الامتحانات والواجبات والماليات',
                'كامل أدوات الذكاء الاصطناعي (AI) على الحصص',
                'دوري الكورس والشهادات المعتمدة بكود QR',
            ],
            TeacherPlanTier::PLUS => [
                'حتى 12 مجموعة دراسية بطلاب مفتوحين',
                'كورسات وحصص غير محدودة (بدون AI)',
                'الجدول الأسبوعي + الامتحانات والواجبات',
                'دوري الكورس والشهادات والماليات',
            ],
            TeacherPlanTier::FREE => [
                'كورس واحد (حتى 3 حصص بدون AI)',
                'مجموعة دراسية واحدة (حتى 50 طالب)',
                'الحضور والغياب وإدارة قائمة الطلاب',
            ],
        };

        $highlightsEn = match ($tier) {
            TeacherPlanTier::PRO => [
                'Unlimited Groups, Courses & Lessons',
                'Weekly Schedule, Exams, Homework & Financials',
                'Full Lesson AI Suite (Transcript & Mind Maps)',
                'Course Leagues & QR-Verified Certificates',
            ],
            TeacherPlanTier::PLUS => [
                'Up to 12 Study Groups (Unlimited Students)',
                'Unlimited Courses & Lessons (No AI)',
                'Weekly Schedule, Exams & Homework',
                'Course Leagues, Certificates & Financials',
            ],
            TeacherPlanTier::FREE => [
                '1 Course (Up to 3 Lessons, No AI)',
                '1 Study Group (Up to 50 Students)',
                'Student Roster & QR Attendance',
            ],
        };

        return [
            'id' => $active?->uuid,
            'planTier' => $tier->value,
            'planNameAr' => $active?->plan_name_ar ?: $tier->labelAr(),
            'planNameEn' => $active?->plan_name_en ?: $tier->labelEn(),
            'billingPeriod' => $period->value,
            'status' => $active?->status ?? 'active',
            'isFree' => $isFree,
            'canCancel' => ! $isFree,
            'mustCancelToSwitch' => ! $isFree,
            'startsAt' => $active?->starts_at?->toDateString() ?? $teacher->created_at?->toDateString(),
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
     * Get active plan, plan catalog, usage quotas, and invoice history for the teacher.
     *
     * @return array<string, mixed>
     */
    public function getBillingSummary(User $teacher): array
    {
        $activeSub = $this->getActiveSubscription($teacher);
        $usage = $this->getUsageStats($teacher);
        $plans = $this->getPlansCatalog();

        $invoices = TeacherInvoice::where('teacher_id', $teacher->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (TeacherInvoice $inv) {
                $methodValue = $inv->payment_method?->value ?? 'vodafone';
                $methodAr = $inv->payment_method?->label() ?? 'فودافون كاش';
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
                    'discountEgp' => (float) $inv->discount_amount_egp,
                    'discountAmountEgp' => (float) $inv->discount_amount_egp,
                    'finalAmountEgp' => $amountNum,
                    'amountEgp' => $amountNum,
                    'amount' => "{$amountNum} ج.م",
                    'amountEn' => "{$amountNum} EGP",
                    'couponCode' => $inv->coupon_code,
                    'paymentMethod' => $methodValue,
                    'method' => $methodValue,
                    'methodLabelAr' => $methodAr,
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
     * Validate coupon code against database and default promotional codes.
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
                'discountEgp' => 0.0,
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
                    'discountEgp' => 0.0,
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
                'discountEgp' => $discountAmt,
                'finalAmountEgp' => $finalAmt,
                'messageAr' => "تم تطبيق كود الخصم بنجاح ({$coupon->discount_percent}%)",
            ];
        }

        // Promotional platform coupons fallback
        $knownCoupons = ['TEACHER2026', 'MOTAFAWWEQ2026', 'SALMA2026'];
        if (in_array($clean, $knownCoupons, true)) {
            $discountPct = 25;
            $discountAmt = round($orderAmount * 0.25, 2);
            $finalAmt = max(0.0, round($orderAmount - $discountAmt, 2));

            return [
                'isValid' => true,
                'code' => $clean,
                'couponCode' => $clean,
                'discountPercent' => $discountPct,
                'discountAmount' => $discountAmt,
                'discountEgp' => $discountAmt,
                'finalAmountEgp' => $finalAmt,
                'messageAr' => "تم تطبيق كود الخصم بنجاح ({$discountPct}%)",
            ];
        }

        return [
            'isValid' => false,
            'messageAr' => 'كود الخصم غير صحيح. جرب كود: TEACHER2026',
            'discountPercent' => 0,
            'discountAmount' => 0.0,
            'discountEgp' => 0.0,
            'finalAmountEgp' => $orderAmount,
        ];
    }

    /**
     * Activate a paid teacher subscription atomically.
     * STRICT RULE: A teacher with an active paid subscription MUST cancel it before subscribing to another plan.
     */
    public function subscribeOrUpgrade(User $teacher, array $data): array
    {
        $rawTier = strtolower(trim((string) ($data['plan_tier'] ?? $data['planTier'] ?? '')));
        $tier = TeacherPlanTier::tryFrom($rawTier);

        if (! $tier) {
            throw ValidationException::withMessages([
                'plan_tier' => ['الباقة المحددة غير صالحة.'],
            ]);
        }

        if ($tier === TeacherPlanTier::FREE) {
            throw ValidationException::withMessages([
                'plan_tier' => [__('messages.billing.cannot_subscribe_free')],
            ]);
        }

        // Enforce mandatory cancel-before-switch rule
        $currentTier = $this->resolveActivePlanTier($teacher);
        if ($currentTier !== TeacherPlanTier::FREE) {
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

        $basePrice = (float) (self::PRICING[$tier->value][$period->value] ?? 120.0);
        $paymentMethodRaw = strtolower(trim((string) ($data['payment_method'] ?? 'vodafone')));
        $paymentMethod = PaymentMethod::tryFrom($paymentMethodRaw) ?? PaymentMethod::VODAFONE;

        $senderRefParts = array_filter([
            ! empty($data['sender_phone']) ? trim(strip_tags((string) $data['sender_phone'])) : null,
            ! empty($data['sender_ipa']) ? trim(strip_tags((string) $data['sender_ipa'])) : null,
            ! empty($data['transaction_ref']) ? trim(strip_tags((string) $data['transaction_ref'])) : null,
        ]);
        $senderReference = ! empty($senderRefParts) ? implode(' | ', $senderRefParts) : null;

        $periodLabelAr = match ($period) {
            BillingPeriod::MONTHLY => 'شهرياً',
            BillingPeriod::TERM => 'نصف سنوي (ترم دراسي)',
            BillingPeriod::ANNUAL => 'سنة دراسية كاملة',
        };

        $planNameAr = "{$tier->labelAr()} — {$periodLabelAr}";
        $planNameEn = "{$tier->labelEn()} ({$period->value})";

        $startsAt = Carbon::now();
        $renewsAt = $startsAt->copy()->addDays($period->durationDays());

        /** @var TeacherSubscription $subscription */
        /** @var TeacherInvoice $invoice */
        [$subscription, $invoice] = DB::transaction(function () use (
            $teacher,
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

            // Close any residual active free rows before activating the paid subscription
            TeacherSubscription::where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->update(['status' => 'superseded']);

            $sub = TeacherSubscription::create([
                'teacher_id' => $teacher->id,
                'plan_tier' => $tier,
                'plan_name_ar' => $planNameAr,
                'plan_name_en' => $planNameEn,
                'billing_period' => $period,
                'amount_egp' => $finalPrice,
                'starts_at' => $startsAt,
                'renews_at' => $renewsAt,
                'status' => 'active',
            ]);

            // Ensure teacher profile exists and stays synced with active tier
            $this->syncProfilePlanTier($teacher, $tier, true);

            $inv = TeacherInvoice::create([
                'invoice_number' => TeacherInvoice::generateUniqueInvoiceNumber(),
                'teacher_id' => $teacher->id,
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
            'teacher.plan_subscribed',
            [
                'teacher_id' => $teacher->id,
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
            'activeSub' => $this->getActiveSubscription($teacher),
            'usage' => $this->getUsageStats($teacher),
            'daysRemaining' => $period->durationDays(),
            'messageAr' => __('messages.billing.subscribed_successfully'),
        ];
    }

    /**
     * Cancel the teacher's active paid subscription and revert their account to the Free tier.
     */
    public function cancelSubscription(User $teacher): array
    {
        $currentTier = $this->resolveActivePlanTier($teacher);

        if ($currentTier === TeacherPlanTier::FREE) {
            throw ValidationException::withMessages([
                'subscription' => [__('messages.billing.no_active_paid_subscription')],
            ]);
        }

        $cancelledSub = DB::transaction(function () use ($teacher) {
            /** @var TeacherSubscription|null $active */
            $active = TeacherSubscription::where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            if ($active) {
                $active->update([
                    'status' => 'cancelled',
                    'cancelled_at' => Carbon::now(),
                ]);
            }

            TeacherSubscription::where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => Carbon::now(),
                ]);

            $this->syncProfilePlanTier($teacher, TeacherPlanTier::FREE, true);

            return $active;
        });

        $this->logSecurityEvent(
            'teacher.plan_cancelled',
            [
                'teacher_id' => $teacher->id,
                'previous_plan' => $currentTier->value,
                'subscription_uuid' => $cancelledSub?->uuid,
            ],
            'info',
            $cancelledSub
        );

        return [
            'activeSub' => $this->getActiveSubscription($teacher),
            'usage' => $this->getUsageStats($teacher),
            'previousPlan' => $currentTier->value,
            'messageAr' => __('messages.billing.cancelled_successfully'),
        ];
    }

    /**
     * Keep teacher_profiles.plan_tier synchronized with the authoritative subscription state.
     */
    protected function syncProfilePlanTier(User $teacher, TeacherPlanTier $tier, bool $createIfMissing = false): void
    {
        if ($createIfMissing) {
            $profile = $this->profileRepository->firstOrCreateForTeacher($teacher);
            $profile->forceFill(['plan_tier' => $tier])->save();
            $teacher->setRelation('teacherProfile', $profile);

            return;
        }

        TeacherProfile::where('user_id', $teacher->id)->update([
            'plan_tier' => $tier->value,
        ]);

        if ($teacher->relationLoaded('teacherProfile') && $teacher->teacherProfile) {
            $teacher->teacherProfile->forceFill(['plan_tier' => $tier]);
        }
    }
}
