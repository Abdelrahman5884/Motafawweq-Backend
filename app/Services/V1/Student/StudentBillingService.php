<?php

namespace App\Services\V1\Student;

use App\Enums\BillingPeriod;
use App\Enums\CourseStatus;
use App\Enums\DirectPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\StudentPlanTier;
use App\Models\Course;
use App\Models\DiscountCoupon;
use App\Models\PlatformSubscriptionPlan;
use App\Models\StudentDirectPayment;
use App\Models\StudentInvoice;
use App\Models\StudentSubscription;
use App\Models\StudyGroup;
use App\Models\TeacherPayoutAccount;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\SubscriptionReceiptService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StudentBillingService extends BaseService
{
    /**
     * Authoritative fallback pricing matrix (EGP) for the 3 student subscription tiers:
     * Free (0 EGP), Plus (50 / 200 / 350 EGP), Pro (80 / 320 / 560 EGP).
     * Live DB values (including Super Admin discounts & temporary free promos) always take precedence.
     */
    public const PRICING = [
        'free' => [
            'monthly' => 0.0,
            'term' => 0.0,
            'annual' => 0.0,
        ],
        'plus' => [
            'monthly' => 50.0,
            'term' => 200.0,
            'annual' => 350.0,
        ],
        'pro' => [
            'monthly' => 80.0,
            'term' => 320.0,
            'annual' => 560.0,
        ],
    ];

    public function __construct(
        protected StudentAccessService $accessService,
        protected SubscriptionReceiptService $receiptService
    ) {}

    /**
     * Authoritative 3-tier student subscription plans catalog (Free, Plus, Pro).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPlansCatalog(): array
    {
        return PlatformSubscriptionPlan::overlayRoleCatalog('student', [
            [
                'id' => 'plan-free',
                'tier' => StudentPlanTier::FREE->value,
                'nameAr' => 'باقة الطالب المجانية',
                'nameEn' => 'Student Free',
                'subtitleAr' => 'مثالية للبدء ومتابعة المقررات والواجبات اليومية مجاناً.',
                'subtitleEn' => 'Great for getting started with lessons, schedules, and homework for free.',
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
                    'الوصول للحصص المنشورة في مجموعاتك والملخصات الأساسية',
                    'الانضمام لمجموعات المعلمين عبر كود الدعوة وحل الواجبات',
                    'متابعة الجدول الأسبوعي وسجل الحضور والاستريك اليومي',
                    'حل الاختبارات الدورية المقررة من المعلم',
                ],
                'featuresEn' => [
                    'Access published lessons and core notes in your groups',
                    'Join teacher study groups via join code and submit homework',
                    'Weekly study schedule, attendance log, and daily streak tracker',
                    'Take scheduled exams assigned by your teachers',
                ],
                'limitationsAr' => [
                    'دوريات المتفوقين (دوري المعلمين ودوري الجمهورية) والأوسمة والتحديات الأسبوعية',
                    'شهادات الإتمام والتفوق المعتمدة بكود QR',
                    'تحويل المحاضرات الذكي بالـ AI وتوليد كويزات البابل شيت',
                ],
                'limitationsEn' => [
                    'Honors Leagues (Course & Republic Leagues), badges & weekly challenges',
                    'Verified QR completion & excellence certificates',
                    'AI Smart Lecture Converter & Bubble-Sheet quiz generation',
                ],
            ],
            [
                'id' => 'plan-plus',
                'tier' => StudentPlanTier::PLUS->value,
                'nameAr' => 'باقة الطالب بلس (Plus)',
                'nameEn' => 'Student Plus',
                'subtitleAr' => 'للطالب الطموح: دوريات المتفوقين، التحديات والأوسمة، الشهادات المعتمدة والاختبارات التدريبية.',
                'subtitleEn' => 'For ambitious students: Honors Leagues, badges, weekly challenges, certificates & practice exams.',
                'popular' => false,
                'pricing' => [
                    'monthly' => [
                        'priceEgp' => 50,
                        'originalPriceEgp' => null,
                        'periodAr' => 'اشتراك شهري (30 يوماً)',
                        'periodEn' => 'Monthly (30 days)',
                        'badgeAr' => 'شاملة الدوريات والتفوق',
                        'badgeEn' => 'Leagues & Honors',
                    ],
                    'term' => [
                        'priceEgp' => 200,
                        'originalPriceEgp' => 250,
                        'periodAr' => 'نصف سنة دراسية (5 شهور)',
                        'periodEn' => 'Semester Pass (5 Months)',
                        'badgeAr' => 'وفر 20%',
                        'badgeEn' => 'Save 20%',
                    ],
                    'annual' => [
                        'priceEgp' => 350,
                        'originalPriceEgp' => 500,
                        'periodAr' => 'سنة دراسية كاملة (12 شهراً)',
                        'periodEn' => 'Full Academic Year',
                        'badgeAr' => 'وفر 30%',
                        'badgeEn' => 'Save 30%',
                    ],
                ],
                'featuresAr' => [
                    'كل مزايا الباقة المجانية بدون قيود أو إعلانات',
                    'المنافسة الكاملة في دوري المعلمين ودوري أبطال الجمهورية ونظام النقاط والترتيب',
                    'لوحة إنجازاتي الكاملة: التحديات الأسبوعية، شارات التميز، وخريطة الالتزام اليومي',
                    'شهادات إتمام وتفوق رسمية موثّقة برقم تسلسلي وكود QR',
                    'اختبارات تدريبية وبنوك أسئلة غير محدودة مع تحليلات الأداء وتقارير ولي الأمر',
                ],
                'featuresEn' => [
                    'Everything in Student Free with zero limits',
                    'Full access to Teacher Course Leagues, Republic League & official rankings',
                    'Achievements hub: weekly challenges, badges & 52-week activity heatmap',
                    'Official verified certificates with serial ID and QR verification',
                    'Unlimited practice exams, performance analytics & weekly parent reports',
                ],
                'limitationsAr' => [
                    'تحويل المحاضرات الذكي بالـ AI (صوت وفيديو وPDF) وتوليد كويزات البابل شيت الفورية',
                ],
                'limitationsEn' => [
                    'AI Smart Lecture Converter (Audio, Video & PDF) & instant Bubble-Sheet quiz generator',
                ],
            ],
            [
                'id' => 'plan-pro',
                'tier' => StudentPlanTier::PRO->value,
                'nameAr' => 'باقة الطالب برو (Pro)',
                'nameEn' => 'Student Pro',
                'subtitleAr' => 'كل مزايا بلس والدوريات + كامل أدوات الذكاء الاصطناعي لتحويل المحاضرات وتفوق الثانوية العامة.',
                'subtitleEn' => 'Everything in Plus & Leagues + the complete AI study suite for top academic performance.',
                'popular' => true,
                'pricing' => [
                    'monthly' => [
                        'priceEgp' => 80,
                        'originalPriceEgp' => null,
                        'periodAr' => 'اشتراك شهري (30 يوماً)',
                        'periodEn' => 'Monthly (30 days)',
                        'badgeAr' => 'الأكثر طلباً • شاملة AI',
                        'badgeEn' => 'Most Popular • AI',
                    ],
                    'term' => [
                        'priceEgp' => 320,
                        'originalPriceEgp' => 400,
                        'periodAr' => 'نصف سنة دراسية (5 شهور)',
                        'periodEn' => 'Semester Pass (5 Months)',
                        'badgeAr' => 'الأكثر طلباً • وفر 20%',
                        'badgeEn' => 'Best Seller • Save 20%',
                    ],
                    'annual' => [
                        'priceEgp' => 560,
                        'originalPriceEgp' => 800,
                        'periodAr' => 'سنة دراسية كاملة (12 شهراً)',
                        'periodEn' => 'Full Academic Year',
                        'badgeAr' => 'الأقوى • وفر 30%',
                        'badgeEn' => 'Best Value • Save 30%',
                    ],
                ],
                'featuresAr' => [
                    'كل مزايا باقة الطالب بلس (الدوريات والأوسمة والشهادات) بلا حدود',
                    'تحويل المحاضرات الذكي بالـ AI (تفريغ صوت وفيديو وPDF إلى ملخصات)',
                    'توليد كويزات البابل شيت الفورية من أي محاضرة مع شرح كل إجابة',
                    'مركز تقوية نقاط الضعف الذكي وخرائط المفاهيم وخطة المراجعة الشخصية',
                    'أولوية كاملة في معسكرات المراجعة النهائية ودعم فني سريع',
                ],
                'featuresEn' => [
                    'Everything in Student Plus (Leagues, Badges & Certificates) unlimited',
                    'AI Smart Lecture Converter (Audio, Video & PDF to smart summaries)',
                    'Instant Bubble-Sheet quiz generator with step-by-step explanations',
                    'AI Weak-Areas Hub, concept maps & personalized revision planner',
                    'Priority access to final revision bootcamps & fast support',
                ],
                'limitationsAr' => [],
                'limitationsEn' => [],
            ],
        ]);
    }

    /**
     * Resolve only the student's active plan tier (auto-expiring past-due paid plans to Free).
     */
    public function resolveActivePlanTier(User $student): StudentPlanTier
    {
        /** @var StudentSubscription|null $active */
        $active = StudentSubscription::forStudent($student->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $active) {
            return StudentPlanTier::FREE;
        }

        $tier = $active->plan_tier instanceof StudentPlanTier
            ? $active->plan_tier
            : (StudentPlanTier::tryFrom((string) $active->plan_tier) ?? StudentPlanTier::FREE);

        if ($tier !== StudentPlanTier::FREE && $active->renews_at && $active->renews_at->isPast()) {
            $active->update(['status' => 'expired']);

            return StudentPlanTier::FREE;
        }

        return $tier;
    }

    /**
     * Resolve student's active subscription (auto-expiring past-due paid plans back to Free).
     */
    public function getActiveSubscription(User $student): array
    {
        /** @var StudentSubscription|null $active */
        $active = StudentSubscription::forStudent($student->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($active && $active->plan_tier !== StudentPlanTier::FREE && $active->renews_at && $active->renews_at->isPast()) {
            $active->update(['status' => 'expired']);
            $active = null;
        }

        if (! $active) {
            return [
                'id' => null,
                'planTier' => StudentPlanTier::FREE->value,
                'planNameAr' => StudentPlanTier::FREE->labelAr(),
                'planNameEn' => StudentPlanTier::FREE->labelEn(),
                'billingPeriod' => BillingPeriod::MONTHLY->value,
                'status' => 'active',
                'isFree' => true,
                'isFreePromo' => false,
                'canCancel' => false,
                'mustCancelToSwitch' => false,
                'startsAt' => $student->created_at?->toDateString(),
                'renewalDate' => null,
                'daysRemaining' => null,
                'amountEgp' => 0.0,
                'highlightsAr' => [
                    'الحصص المسجلة ومجموعات المعلمين',
                    'الواجبات والامتحانات الدورية',
                    'الجدول الأسبوعي والاستريك اليومي',
                ],
                'highlightsEn' => [
                    'Recorded lessons & study groups',
                    'Homeworks & periodic exams',
                    'Weekly schedule & daily streak',
                ],
                'unlockedFeatures' => StudentPlanTier::FREE->unlockedFeatures(),
            ];
        }

        $tier = $active->plan_tier instanceof StudentPlanTier
            ? $active->plan_tier
            : (StudentPlanTier::tryFrom((string) $active->plan_tier) ?? StudentPlanTier::FREE);

        $period = $active->billing_period instanceof BillingPeriod
            ? $active->billing_period
            : (BillingPeriod::tryFrom((string) $active->billing_period) ?? BillingPeriod::MONTHLY);

        $isFree = $tier === StudentPlanTier::FREE;
        $daysRemaining = (! $isFree && $active->renews_at)
            ? max(0, (int) ceil(Carbon::now()->diffInHours($active->renews_at, false) / 24))
            : null;

        $highlightsAr = match ($tier) {
            StudentPlanTier::PRO => [
                'تحويل المحاضرات الذكي بالـ AI',
                'توليد كويزات البابل شيت الفورية',
                'شهادات رسمية بكود QR',
                'تقارير أسبوعية لولي الأمر',
            ],
            StudentPlanTier::PLUS => [
                'دوري المعلمين والجمهورية والأوسمة',
                'التحديات الأسبوعية ولوحة الإنجازات',
                'شهادات رسمية بكود QR',
                'اختبارات وتحليلات أداء وتقارير ولي الأمر',
            ],
            StudentPlanTier::FREE => [
                'الحصص المسجلة ومجموعات المعلمين',
                'الواجبات والامتحانات الدورية',
                'الجدول الأسبوعي والاستريك اليومي',
            ],
        };

        $highlightsEn = match ($tier) {
            StudentPlanTier::PRO => [
                'AI Smart Lecture Converter',
                'Instant Bubble-Sheet Quizzes',
                'Official QR Certificates',
                'Weekly Parent Reports',
            ],
            StudentPlanTier::PLUS => [
                'Course & Republic Leagues',
                'Weekly Challenges & Badges',
                'Official QR Certificates',
                'Analytics & Parent Reports',
            ],
            StudentPlanTier::FREE => [
                'Recorded Lessons & Groups',
                'Homeworks & Periodic Exams',
                'Weekly Schedule & Streak',
            ],
        };

        return [
            'id' => $active->uuid,
            'planTier' => $tier->value,
            'planNameAr' => $active->plan_name_ar,
            'planNameEn' => $active->plan_name_en ?: $tier->labelEn(),
            'billingPeriod' => $period->value,
            'status' => $active->status,
            'isFree' => $isFree,
            'isFreePromo' => (bool) $active->is_free_promo,
            'canCancel' => ! $isFree,
            'mustCancelToSwitch' => ! $isFree,
            'startsAt' => $active->starts_at?->toDateString(),
            'renewalDate' => $isFree ? null : $active->renews_at?->toDateString(),
            'daysRemaining' => $daysRemaining,
            'amountEgp' => (float) $active->amount_egp,
            'highlightsAr' => $highlightsAr,
            'highlightsEn' => $highlightsEn,
            'unlockedFeatures' => $tier->unlockedFeatures(),
        ];
    }

    /**
     * Format a pending or rejected subscription request for the student billing page.
     *
     * @return array<string, mixed>|null
     */
    protected function formatSubscriptionRequest(?StudentSubscription $sub): ?array
    {
        if (! $sub) {
            return null;
        }

        $tierVal = $sub->plan_tier instanceof StudentPlanTier ? $sub->plan_tier->value : (string) $sub->plan_tier;
        $periodVal = $sub->billing_period instanceof BillingPeriod ? $sub->billing_period->value : (string) $sub->billing_period;
        $pmEnum = PaymentMethod::tryFrom((string) ($sub->payment_method ?? ''));

        return [
            'id' => $sub->uuid,
            'planTier' => $tierVal,
            'planNameAr' => $sub->plan_name_ar,
            'planNameEn' => $sub->plan_name_en ?: $sub->plan_name_ar,
            'billingPeriod' => $periodVal,
            'amountEgp' => (float) $sub->amount_egp,
            'baseAmountEgp' => (float) ($sub->base_amount_egp ?? $sub->amount_egp),
            'discountAmountEgp' => (float) ($sub->discount_amount_egp ?? 0),
            'couponCode' => $sub->coupon_code,
            'paymentMethod' => (string) ($sub->payment_method ?? ''),
            'paymentMethodLabelAr' => $pmEnum?->label() ?? (string) ($sub->payment_method ?? ''),
            'transactionReference' => $sub->transaction_reference,
            'senderReference' => $sub->sender_reference,
            'hasReceipt' => ! empty($sub->receipt_path),
            'status' => (string) $sub->status,
            'rejectionReason' => $sub->rejection_reason,
            'submittedAt' => $sub->created_at?->toIso8601String(),
            'submittedDate' => $sub->created_at?->format('Y-m-d H:i'),
            'reviewedAt' => $sub->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{pendingRequest: array<string, mixed>|null, latestRejectedRequest: array<string, mixed>|null}
     */
    protected function resolvePendingAndRejectedRequests(User $student): array
    {
        $pending = StudentSubscription::forStudent($student->id)
            ->where('status', 'pending_review')
            ->latest('id')
            ->first();

        $latestActiveOrPendingId = StudentSubscription::forStudent($student->id)
            ->whereIn('status', ['active', 'pending_review'])
            ->max('id') ?? 0;

        $rejected = StudentSubscription::forStudent($student->id)
            ->where('status', 'rejected')
            ->where('id', '>', (int) $latestActiveOrPendingId)
            ->latest('id')
            ->first();

        return [
            'pendingRequest' => $this->formatSubscriptionRequest($pending),
            'latestRejectedRequest' => $this->formatSubscriptionRequest($rejected),
        ];
    }

    /**
     * Get the student's active subscription, 3 official tiers, unified invoices, direct payments, and teacher payout accounts.
     */
    public function getBillingOverview(User $student): array
    {
        $activeSub = $this->getActiveSubscription($student);
        $requestState = $this->resolvePendingAndRejectedRequests($student);
        $plans = $this->getPlansCatalog();

        $payments = StudentDirectPayment::query()
            ->where('student_id', $student->id)
            ->with(['teacher:id,name'])
            ->latest('paid_at')
            ->get()
            ->map(function (StudentDirectPayment $p) {
                $methodEnum = $p->payment_method instanceof PaymentMethod
                    ? $p->payment_method
                    : PaymentMethod::tryFrom((string) $p->payment_method);
                $statusVal = $p->status instanceof DirectPaymentStatus ? $p->status->value : (string) $p->status;

                return [
                    'id' => $p->uuid,
                    'referenceNum' => $p->reference_num,
                    'courseNameAr' => $p->course_name_ar,
                    'teacherName' => $p->teacher?->name,
                    'amountEgp' => (float) $p->amount_egp,
                    'paymentMethod' => $methodEnum?->value ?? (string) $p->payment_method,
                    'paymentMethodLabelAr' => $methodEnum?->label() ?? (string) $p->payment_method,
                    'status' => $statusVal,
                    'paidAt' => $p->paid_at?->toIso8601String(),
                    'confirmedAt' => $p->confirmed_at?->toIso8601String(),
                    'rejectionReason' => $p->rejection_reason,
                ];
            })->values()->all();

        $subInvoices = StudentInvoice::forStudent($student->id)
            ->latest('paid_at')
            ->latest('id')
            ->get()
            ->map(function (StudentInvoice $inv) {
                $methodEnum = $inv->payment_method instanceof PaymentMethod
                    ? $inv->payment_method
                    : PaymentMethod::tryFrom((string) $inv->payment_method);
                $statusVal = $inv->status instanceof InvoiceStatus ? $inv->status->value : (string) $inv->status;

                return [
                    'id' => $inv->invoice_number,
                    'uuid' => $inv->uuid,
                    'kind' => 'subscription',
                    'date' => ($inv->paid_at ?? $inv->created_at)?->format('Y-m-d'),
                    'sortAt' => ($inv->paid_at ?? $inv->created_at)?->toIso8601String(),
                    'descriptionAr' => $inv->description_ar,
                    'descriptionEn' => $inv->description_en ?: $inv->description_ar,
                    'baseAmountEgp' => (float) $inv->base_amount_egp,
                    'discountAmountEgp' => (float) $inv->discount_amount_egp,
                    'amountEgp' => (float) $inv->final_amount_egp,
                    'couponCode' => $inv->coupon_code,
                    'method' => $methodEnum?->value ?? (string) $inv->payment_method,
                    'methodAr' => $methodEnum?->label() ?? (string) $inv->payment_method,
                    'methodEn' => ucfirst($methodEnum?->value ?? (string) $inv->payment_method),
                    'transactionReference' => $inv->transaction_reference,
                    'status' => $statusVal === 'failed' ? 'rejected' : ($statusVal === 'paid' ? 'paid' : $statusVal),
                    'rejectionReason' => $inv->rejection_reason,
                ];
            })->all();

        $directInvoices = array_map(fn (array $p) => [
            'id' => $p['referenceNum'] ?: $p['id'],
            'uuid' => $p['id'],
            'kind' => 'course_payment',
            'date' => substr((string) ($p['paidAt'] ?? ''), 0, 10),
            'sortAt' => $p['paidAt'],
            'descriptionAr' => $p['teacherName']
                ? "{$p['courseNameAr']} — {$p['teacherName']}"
                : $p['courseNameAr'],
            'descriptionEn' => $p['courseNameAr'],
            'baseAmountEgp' => $p['amountEgp'],
            'discountAmountEgp' => 0.0,
            'amountEgp' => $p['amountEgp'],
            'couponCode' => null,
            'method' => $p['paymentMethod'],
            'methodAr' => $p['paymentMethodLabelAr'],
            'methodEn' => ucfirst((string) $p['paymentMethod']),
            'status' => $p['status'] === 'confirmed' ? 'paid' : $p['status'],
            'rejectionReason' => $p['rejectionReason'],
        ], $payments);

        $invoices = array_merge($subInvoices, $directInvoices);
        usort($invoices, fn (array $a, array $b) => strcmp((string) ($b['sortAt'] ?? ''), (string) ($a['sortAt'] ?? '')));

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
            'activeSub' => $activeSub,
            'pendingRequest' => $requestState['pendingRequest'],
            'latestRejectedRequest' => $requestState['latestRejectedRequest'],
            'plans' => $plans,
            'invoices' => $invoices,
            'payments' => $payments,
            'payoutAccounts' => $payoutAccounts,
        ];
    }

    /**
     * Subscribe to a student tier (Plus / Pro).
     * - STRICT cancel-before-switch rule: active paid plan OR pending request must be cancelled first.
     * - Free effective price (e.g. Super Admin temporary free promo or 100% coupon): activates immediately without receipt.
     * - Paid effective price (> 0 EGP): requires transaction reference + payment receipt & waits for Super Admin approval.
     */
    public function subscribeOrUpgrade(User $student, array $data): array
    {
        $rawTier = strtolower(trim((string) ($data['plan_tier'] ?? '')));
        $tier = StudentPlanTier::tryFrom($rawTier);
        if (! $tier) {
            throw ValidationException::withMessages(['plan_tier' => ['الباقة المحددة غير صالحة.']]);
        }

        if ($tier === StudentPlanTier::FREE) {
            throw ValidationException::withMessages([
                'plan_tier' => ['للعودة إلى الباقة المجانية يرجى إلغاء اشتراكك الحالي من زر «إلغاء الاشتراك».'],
            ]);
        }

        // Enforce no duplicate pending approval request
        $existingPending = StudentSubscription::forStudent($student->id)
            ->where('status', 'pending_review')
            ->latest('id')
            ->first();

        if ($existingPending) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'لديك طلب اشتراك قيد المراجعة حالياً. يرجى انتظار اعتماد الإدارة أو إلغاء الطلب المعلّق أولاً.',
                'error_code' => 'PENDING_SUBSCRIPTION_EXISTS',
                'pending_request' => $this->formatSubscriptionRequest($existingPending),
            ], Response::HTTP_CONFLICT));
        }

        // Enforce mandatory cancel-before-switch rule for active paid plans
        $currentTier = $this->resolveActivePlanTier($student);
        if ($currentTier !== StudentPlanTier::FREE) {
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

        $rawPeriod = strtolower(trim((string) ($data['billing_period'] ?? BillingPeriod::MONTHLY->value)));
        $period = BillingPeriod::tryFrom($rawPeriod) ?? BillingPeriod::MONTHLY;

        $fallbackPrice = (float) (self::PRICING[$tier->value][$period->value] ?? 0.0);
        $resolvedPricing = PlatformSubscriptionPlan::resolveAuthoritativePrice(
            'student',
            $tier->value,
            $period->value,
            $fallbackPrice
        );
        $basePrice = (float) $resolvedPricing['basePrice'];
        $isFreePromo = (bool) $resolvedPricing['isFreePromo'];

        $paymentMethodRaw = strtolower(trim((string) ($data['payment_method'] ?? PaymentMethod::VODAFONE->value)));
        $paymentMethod = PaymentMethod::tryFrom($paymentMethodRaw) ?? PaymentMethod::VODAFONE;

        $transactionRef = trim(strip_tags((string) ($data['transaction_ref'] ?? $data['transaction_reference'] ?? '')));
        $senderRefParts = array_filter([
            ! empty($data['sender_phone']) ? trim(strip_tags((string) $data['sender_phone'])) : null,
            ! empty($data['sender_ipa']) ? trim(strip_tags((string) $data['sender_ipa'])) : null,
            $transactionRef !== '' ? $transactionRef : null,
        ]);
        $senderReference = ! empty($senderRefParts) ? implode(' | ', $senderRefParts) : null;

        /** @var UploadedFile|null $receiptFile */
        $receiptFile = ($data['receipt_file'] ?? null) instanceof UploadedFile ? $data['receipt_file'] : null;

        $periodLabelAr = $period->label();
        $displayTierAr = $resolvedPricing['planNameAr'] ?: $tier->labelAr();
        $displayTierEn = $resolvedPricing['planNameEn'] ?: $tier->labelEn();
        $planNameAr = "{$displayTierAr} — {$periodLabelAr}";
        $planNameEn = "{$displayTierEn} ({$period->value})";

        return $this->transaction(function () use (
            $student,
            $tier,
            $period,
            $basePrice,
            $isFreePromo,
            $resolvedPricing,
            $paymentMethod,
            $transactionRef,
            $senderReference,
            $receiptFile,
            $planNameAr,
            $planNameEn,
            $data
        ) {
            $discountAmount = 0.0;
            $appliedCouponCode = null;

            if ($basePrice > 0 && ! empty($data['coupon_code'])) {
                $code = strtoupper(trim(strip_tags((string) $data['coupon_code'])));
                /** @var DiscountCoupon|null $coupon */
                $coupon = DiscountCoupon::where('code', $code)->lockForUpdate()->first();

                if (
                    ! $coupon
                    || ! $coupon->is_active
                    || ($coupon->expires_at && $coupon->expires_at->isPast())
                    || ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses)
                ) {
                    throw ValidationException::withMessages(['coupon_code' => ['كود الخصم غير صالح أو منتهي الصلاحية.']]);
                }

                if ($basePrice < (float) $coupon->min_order_egp) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ["الحد الأدنى لتطبيق هذا الكوبون هو {$coupon->min_order_egp} ج.م."],
                    ]);
                }

                $discountAmount = $coupon->calculateDiscount($basePrice);
                $appliedCouponCode = $coupon->code;
                $coupon->increment('used_count');
            }

            $finalAmount = max(0.0, round($basePrice - $discountAmount, 2));
            $requiresApproval = $finalAmount > 0.0;

            if ($requiresApproval) {
                if (mb_strlen($transactionRef) < 3) {
                    throw ValidationException::withMessages([
                        'transaction_ref' => ['يرجى إدخال كود العملية / الرقم المرجعي للتحويل (3 أحرف على الأقل).'],
                    ]);
                }
                if (! $receiptFile) {
                    throw ValidationException::withMessages([
                        'receipt' => ['يرجى إرفاق صورة أو ملف إيصال التحويل ليتم مراجعته واعتماده من إدارة المنصة.'],
                    ]);
                }
            }

            $receiptMeta = $receiptFile
                ? $this->receiptService->storeReceipt($receiptFile, 'student', (int) $student->id)
                : null;

            $startsAt = Carbon::now();
            $renewsAt = $isFreePromo && $resolvedPricing['freePromoUntil'] instanceof Carbon && $resolvedPricing['freePromoUntil']->isFuture()
                ? $resolvedPricing['freePromoUntil']->copy()
                : $startsAt->copy()->addDays($period->durationDays());

            if (! $requiresApproval) {
                StudentSubscription::forStudent($student->id)
                    ->where('status', 'active')
                    ->update(['status' => 'superseded']);
            }

            /** @var StudentSubscription $subscription */
            $subscription = StudentSubscription::create([
                'student_id' => $student->id,
                'plan_tier' => $tier,
                'plan_name_ar' => $planNameAr,
                'plan_name_en' => $planNameEn,
                'billing_period' => $period,
                'amount_egp' => $finalAmount,
                'base_amount_egp' => $basePrice,
                'discount_amount_egp' => $discountAmount,
                'coupon_code' => $appliedCouponCode,
                'payment_method' => $requiresApproval ? $paymentMethod->value : 'free_promo',
                'transaction_reference' => $transactionRef !== '' ? $transactionRef : null,
                'sender_reference' => $senderReference,
                'receipt_path' => $receiptMeta['path'] ?? null,
                'receipt_disk' => $receiptMeta['disk'] ?? 'local',
                'receipt_original_name' => $receiptMeta['original_name'] ?? null,
                'receipt_mime_type' => $receiptMeta['mime_type'] ?? null,
                'is_free_promo' => $isFreePromo,
                'starts_at' => $startsAt,
                'renews_at' => $renewsAt,
                'status' => $requiresApproval ? 'pending_review' : 'active',
            ]);

            /** @var StudentInvoice $invoice */
            $invoice = StudentInvoice::create([
                'invoice_number' => StudentInvoice::generateUniqueInvoiceNumber(),
                'student_id' => $student->id,
                'subscription_id' => $subscription->id,
                'description_ar' => "اشتراك {$planNameAr}",
                'description_en' => "Subscription: {$planNameEn}",
                'base_amount_egp' => $basePrice,
                'discount_amount_egp' => $discountAmount,
                'final_amount_egp' => $finalAmount,
                'coupon_code' => $appliedCouponCode,
                'payment_method' => $paymentMethod,
                'sender_reference' => $senderReference,
                'transaction_reference' => $transactionRef !== '' ? $transactionRef : null,
                'receipt_path' => $receiptMeta['path'] ?? null,
                'status' => $requiresApproval ? InvoiceStatus::PENDING : InvoiceStatus::PAID,
                'paid_at' => $requiresApproval ? null : $startsAt,
            ]);

            $invoicePayload = [
                'id' => $invoice->invoice_number,
                'uuid' => $invoice->uuid,
                'amountEgp' => (float) $invoice->final_amount_egp,
                'baseAmountEgp' => (float) $invoice->base_amount_egp,
                'discountAmountEgp' => (float) $invoice->discount_amount_egp,
                'couponCode' => $invoice->coupon_code,
                'paymentMethod' => $paymentMethod->value,
                'transactionReference' => $invoice->transaction_reference,
                'status' => $invoice->status->value,
                'paidAt' => $invoice->paid_at?->toIso8601String(),
            ];

            $this->logSecurityEvent(
                $requiresApproval ? 'student.subscription.submitted_for_review' : 'student.subscription.activated',
                [
                    'student_id' => $student->id,
                    'plan_tier' => $tier->value,
                    'billing_period' => $period->value,
                    'final_amount_egp' => $finalAmount,
                    'is_free_promo' => $isFreePromo,
                    'payment_method' => $paymentMethod->value,
                    'transaction_reference' => $transactionRef !== '' ? $transactionRef : null,
                    'coupon_code' => $appliedCouponCode,
                ],
                'info',
                $subscription
            );

            return [
                'activeSub' => $this->getActiveSubscription($student),
                'pendingRequest' => $requiresApproval ? $this->formatSubscriptionRequest($subscription) : null,
                'requiresApproval' => $requiresApproval,
                'status' => $subscription->status,
                'invoice' => $invoicePayload,
                'messageAr' => $requiresApproval
                    ? "تم إرسال طلب اشتراك {$planNameAr} وإيصال الدفع للمراجعة بنجاح — سيتم تفعيل الباقة فور اعتماد الإدارة."
                    : "تم تفعيل {$planNameAr} مجاناً بنجاح فوراً بدون انتظار!",
            ];
        });
    }

    /**
     * Cancel either a pending approval subscription request OR an active paid student subscription.
     */
    public function cancelSubscription(User $student): array
    {
        return $this->transaction(function () use ($student): array {
            /** @var StudentSubscription|null $pending */
            $pending = StudentSubscription::forStudent($student->id)
                ->where('status', 'pending_review')
                ->latest('id')
                ->first();

            if ($pending) {
                $pending->update([
                    'status' => 'cancelled',
                    'cancelled_at' => Carbon::now(),
                ]);

                StudentInvoice::where('subscription_id', $pending->id)
                    ->where('status', InvoiceStatus::PENDING->value)
                    ->update(['status' => InvoiceStatus::FAILED->value]);

                $this->logSecurityEvent('student.subscription.request_cancelled', [
                    'student_id' => $student->id,
                    'subscription_uuid' => $pending->uuid,
                ], 'info', $pending);

                return [
                    'activeSub' => $this->getActiveSubscription($student),
                    'pendingRequest' => null,
                    'previousPlan' => is_object($pending->plan_tier) ? $pending->plan_tier->value : (string) $pending->plan_tier,
                    'cancelledType' => 'pending_request',
                    'messageAr' => 'تم إلغاء طلب الاشتراك المعلّق بنجاح. يمكنك الآن اختيار باقة أخرى.',
                ];
            }

            $currentTier = $this->resolveActivePlanTier($student);
            if ($currentTier === StudentPlanTier::FREE) {
                throw ValidationException::withMessages([
                    'subscription' => [__('messages.billing.no_active_paid_subscription')],
                ]);
            }

            /** @var StudentSubscription|null $active */
            $active = StudentSubscription::forStudent($student->id)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            StudentSubscription::forStudent($student->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => Carbon::now(),
                ]);

            $this->logSecurityEvent('student.plan_cancelled', [
                'student_id' => $student->id,
                'previous_plan' => $currentTier->value,
                'subscription_uuid' => $active?->uuid,
            ], 'info', $active);

            return [
                'activeSub' => $this->getActiveSubscription($student),
                'pendingRequest' => null,
                'previousPlan' => $currentTier->value,
                'cancelledType' => 'active_subscription',
                'messageAr' => __('messages.billing.cancelled_successfully'),
            ];
        });
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
