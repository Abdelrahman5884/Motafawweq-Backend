<?php

namespace App\Services\V1\Center;

use App\Enums\CenterExpenseCategory;
use App\Enums\EnrollmentStatus;
use App\Models\CenterBranch;
use App\Models\CenterExpense;
use App\Models\CenterProfile;
use App\Models\CenterTeacher;
use App\Models\CenterTeacherSettlement;
use App\Models\GroupEnrollment;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class CenterFinancialService extends BaseService
{
    public function __construct(
        protected CenterBranchService $branchService,
        protected CenterTeacherService $teacherService
    ) {}

    /**
     * Comprehensive Profit & Loss (P&L) breakdown for the center.
     *
     * @return array<string, mixed>
     */
    public function getOverview(User $center, ?string $periodKey = null, ?string $branchUuid = null): array
    {
        $resolvedPeriod = $this->normalizePeriodKey($periodKey);
        [$periodStart, $periodEnd] = $this->periodBounds($resolvedPeriod);

        $branchId = null;
        if ($branchUuid && $branchUuid !== 'all') {
            $branchId = $this->branchService->findOwnedBranch($center, $branchUuid)->id;
        }

        $defaultRate = (float) (CenterProfile::where('user_id', $center->id)->value('default_teacher_settlement_rate') ?? 0.75);

        $groups = StudyGroup::where('center_id', $center->id)
            ->where('status', 'active')
            ->when($branchId !== null, fn ($q) => $q->where('center_branch_id', $branchId))
            ->with(['centerTeacher:id,settlement_rate', 'enrolledEnrollments:id,study_group_id,payment_status,due_amount_egp'])
            ->get();

        $grossCollectedRevenue = 0.0;
        $teacherPayouts = 0.0;
        $outstandingReceivables = 0.0;

        foreach ($groups as $group) {
            $enrolledCount = $group->enrolledEnrollments->count();
            $expectedTuition = $enrolledCount * (float) $group->price_egp;
            $groupDues = (float) $group->enrolledEnrollments->sum('due_amount_egp');
            $collected = max(0.0, $expectedTuition - $groupDues);

            $rate = (float) ($group->centerTeacher?->settlement_rate ?? $defaultRate);
            $grossCollectedRevenue += $collected;
            $teacherPayouts += round($collected * $rate, 2);
            $outstandingReceivables += $groupDues;
        }

        $totalExpenses = (float) CenterExpense::forCenter($center->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('expense_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->sum('amount_egp');

        $grossCollectedRevenue = round($grossCollectedRevenue, 2);
        $teacherPayouts = round($teacherPayouts, 2);
        $centerNetRevenue = round($grossCollectedRevenue - $teacherPayouts, 2);
        $totalExpenses = round($totalExpenses, 2);
        $netProfit = round($centerNetRevenue - $totalExpenses, 2);
        $operatingMarginPct = $grossCollectedRevenue > 0
            ? round(($netProfit / $grossCollectedRevenue) * 100, 1)
            : 0.0;

        $expensesByCategory = CenterExpense::forCenter($center->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('expense_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get(['category', 'amount_egp'])
            ->groupBy(fn ($e) => $e->category instanceof CenterExpenseCategory ? $e->category->value : (string) $e->category)
            ->map(function ($items, $cat) {
                $enum = CenterExpenseCategory::tryFrom((string) $cat) ?? CenterExpenseCategory::OTHER;

                return [
                    'category' => $enum->value,
                    'categoryAr' => $enum->labelAr(),
                    'categoryEn' => $enum->labelEn(),
                    'totalEgp' => round((float) $items->sum('amount_egp'), 2),
                    'count' => $items->count(),
                ];
            })
            ->values()
            ->all();

        return [
            'periodKey' => $resolvedPeriod,
            'defaultTeacherSettlementRate' => $defaultRate,
            'summary' => [
                'grossCollectedRevenue' => $grossCollectedRevenue,
                'teacherPayouts' => $teacherPayouts,
                'centerNetRevenue' => $centerNetRevenue,
                'totalExpenses' => $totalExpenses,
                'netProfit' => $netProfit,
                'operatingMarginPct' => $operatingMarginPct,
                'outstandingReceivables' => round($outstandingReceivables, 2),
            ],
            'expensesByCategory' => $expensesByCategory,
        ];
    }

    /**
     * List operating expenses for the center with filtering.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listExpenses(User $center, array $filters = []): array
    {
        $query = CenterExpense::forCenter($center->id)
            ->with('branch:id,uuid,name_ar')
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if (! empty($filters['branch_id']) && $filters['branch_id'] !== 'all') {
            $branch = $this->branchService->findOwnedBranch($center, (string) $filters['branch_id']);
            $query->where('branch_id', $branch->id);
        }

        if (! empty($filters['category']) && in_array($filters['category'], CenterExpenseCategory::values(), true)) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['period'])) {
            [$start, $end] = $this->periodBounds($this->normalizePeriodKey((string) $filters['period']));
            $query->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()]);
        }

        return $query->get()
            ->map(fn (CenterExpense $e) => $this->formatExpense($e))
            ->values()
            ->all();
    }

    /**
     * Record a new operating expense receipt.
     *
     * @return array<string, mixed>
     */
    public function createExpense(User $center, array $data): array
    {
        $branchId = $this->resolveOwnedBranchId($center, $data['branch_id'] ?? $data['branchId'] ?? null);
        $category = CenterExpenseCategory::tryFrom(strtolower(trim((string) ($data['category'] ?? 'other'))))
            ?? CenterExpenseCategory::OTHER;

        $expense = $this->transaction(function () use ($center, $branchId, $category, $data) {
            $titleAr = trim(strip_tags((string) ($data['title_ar'] ?? $data['titleAr'] ?? 'مصروف تشغيلي')));
            $titleEn = trim(strip_tags((string) ($data['title_en'] ?? $data['titleEn'] ?? $titleAr)));
            $amount = round(max(0.01, (float) ($data['amount_egp'] ?? $data['amountEgp'] ?? 0)), 2);
            $date = ! empty($data['expense_date']) ? Carbon::parse((string) $data['expense_date'])->toDateString() : now()->toDateString();
            $expenseStatus = (string) ($data['status'] ?? 'paid');

            /** @var CenterExpense $created */
            $created = CenterExpense::create([
                'center_id' => $center->id,
                'branch_id' => $branchId,
                'receipt_number' => CenterExpense::generateUniqueReceiptNumber($category->value),
                'title_ar' => $titleAr,
                'title_en' => $titleEn,
                'category' => $category,
                'amount_egp' => $amount,
                'expense_date' => $date,
                'status' => in_array($expenseStatus, ['paid', 'pending'], true) ? $expenseStatus : 'paid',
                'notes' => isset($data['notes']) ? trim(strip_tags((string) $data['notes'])) : null,
                'recorded_by' => $center->id,
            ]);

            $this->logSecurityEvent('center.financials.expense_created', [
                'expense_uuid' => $created->uuid,
                'receipt_number' => $created->receipt_number,
                'category' => $category->value,
                'amount_egp' => $amount,
            ], 'info', $created);

            return $created->load('branch');
        });

        return $this->formatExpense($expense);
    }

    /**
     * Delete an expense receipt with strict ownership verification.
     */
    public function deleteExpense(User $center, string $uuid): bool
    {
        /** @var CenterExpense|null $expense */
        $expense = CenterExpense::where('uuid', $uuid)->first();
        if (! $expense) {
            throw (new ModelNotFoundException)->setModel(CenterExpense::class, [$uuid]);
        }

        $expense->assertOwnedByCenter($center);

        return $this->transaction(function () use ($expense) {
            $receipt = $expense->receipt_number;
            $amount = $expense->amount_egp;
            $expense->delete();

            $this->logSecurityEvent('center.financials.expense_deleted', [
                'expense_uuid' => $expense->uuid,
                'receipt_number' => $receipt,
                'amount_egp' => $amount,
            ], 'warning', $expense);

            return true;
        });
    }

    /**
     * Export expenses register as formula-safe UTF-8 CSV.
     */
    public function exportExpensesCsv(User $center, array $filters = []): string
    {
        $expenses = $this->listExpenses($center, $filters);
        $headers = ['رقم السند', 'بيان المصروف', 'التصنيف', 'الفرع', 'المبلغ (ج.م)', 'التاريخ', 'الحالة'];

        $escape = function (mixed $val): string {
            $s = (string) ($val ?? '');
            if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
                $s = "'".$s;
            }

            return '"'.str_replace('"', '""', $s).'"';
        };

        $lines = [implode(',', array_map($escape, $headers))];
        foreach ($expenses as $e) {
            $lines[] = implode(',', array_map($escape, [
                $e['receiptNumber'],
                $e['titleAr'],
                $e['categoryAr'],
                $e['branchNameAr'],
                $e['amountEgp'],
                $e['date'],
                $e['status'] === 'paid' ? 'مدفوع' : 'معلق',
            ]));
        }

        return "\xEF\xBB\xBF".implode("\n", $lines);
    }

    /**
     * Compute automated teacher payout settlements for a billing period (YYYY-MM), merged with persisted approval states.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTeacherSettlements(User $center, ?string $periodKey = null): array
    {
        $resolvedPeriod = $this->normalizePeriodKey($periodKey);

        $teachers = CenterTeacher::forCenter($center->id)
            ->with([
                'branch:id,uuid,name_ar',
                'studyGroups' => fn ($q) => $q->where('center_id', $center->id)->where('status', 'active')
                    ->with('enrolledEnrollments:id,study_group_id,payment_status,due_amount_egp'),
            ])
            ->get();

        $savedSettlements = CenterTeacherSettlement::forCenter($center->id)
            ->where('period_key', $resolvedPeriod)
            ->get()
            ->keyBy('center_teacher_id');

        return $teachers->map(function (CenterTeacher $teacher) use ($savedSettlements, $resolvedPeriod) {
            $saved = $savedSettlements->get($teacher->id);
            $calc = $this->computeTeacherNumbers($teacher);

            $status = $saved?->status ?? 'pending';

            return [
                'id' => $saved?->uuid ?? $teacher->uuid,
                'settlementId' => $saved?->uuid,
                'teacherId' => $teacher->uuid,
                'periodKey' => $resolvedPeriod,
                'teacherNameAr' => $teacher->name_ar,
                'subjectAr' => $teacher->subject_ar,
                'branchNameAr' => $teacher->branch?->name_ar ?? 'الفرع الرئيسي',
                'groupsCount' => $saved ? (int) $saved->groups_count : $calc['groupsCount'],
                'studentsCount' => $saved ? (int) $saved->paying_students_count : $calc['payingStudentsCount'],
                'grossCollectedEgp' => $saved ? (float) $saved->gross_collected_egp : $calc['grossCollectedEgp'],
                'settlementRate' => $saved ? (float) $saved->settlement_rate : $calc['settlementRate'],
                'teacherShareEgp' => $saved ? (float) $saved->teacher_share_egp : $calc['teacherShareEgp'],
                'centerShareEgp' => $saved ? (float) $saved->center_share_egp : $calc['centerShareEgp'],
                'status' => $status,
                'statusAr' => in_array($status, ['approved', 'paid'], true) ? 'تم الصرف والتحويل' : 'بانتظار الاعتماد',
                'approvedAt' => $saved?->approved_at?->toIso8601String(),
                'referenceNote' => $saved?->reference_note,
            ];
        })->values()->all();
    }

    /**
     * Atomically lock and approve a teacher's payout settlement for the specified period.
     *
     * @return array<string, mixed>
     */
    public function approveTeacherSettlement(
        User $center,
        string $teacherUuid,
        ?string $periodKey = null,
        ?string $referenceNote = null
    ): array {
        $teacher = $this->teacherService->findOwnedTeacher($center, $teacherUuid);
        $teacher->load([
            'branch:id,uuid,name_ar',
            'studyGroups' => fn ($q) => $q->where('center_id', $center->id)->where('status', 'active')
                ->with('enrolledEnrollments:id,study_group_id,payment_status,due_amount_egp'),
        ]);

        $resolvedPeriod = $this->normalizePeriodKey($periodKey);

        $settlement = $this->transaction(function () use ($center, $teacher, $resolvedPeriod, $referenceNote) {
            /** @var CenterTeacherSettlement|null $existing */
            $existing = CenterTeacherSettlement::forCenter($center->id)
                ->where('center_teacher_id', $teacher->id)
                ->where('period_key', $resolvedPeriod)
                ->lockForUpdate()
                ->first();

            if ($existing && in_array($existing->status, ['approved', 'paid'], true)) {
                return $existing;
            }

            $calc = $this->computeTeacherNumbers($teacher);

            $record = CenterTeacherSettlement::updateOrCreate(
                [
                    'center_id' => $center->id,
                    'center_teacher_id' => $teacher->id,
                    'period_key' => $resolvedPeriod,
                ],
                [
                    'groups_count' => $calc['groupsCount'],
                    'paying_students_count' => $calc['payingStudentsCount'],
                    'gross_collected_egp' => $calc['grossCollectedEgp'],
                    'settlement_rate' => $calc['settlementRate'],
                    'teacher_share_egp' => $calc['teacherShareEgp'],
                    'center_share_egp' => $calc['centerShareEgp'],
                    'status' => 'approved',
                    'approved_by' => $center->id,
                    'approved_at' => now(),
                    'paid_at' => now(),
                    'reference_note' => $referenceNote ? trim(strip_tags($referenceNote)) : null,
                ]
            );

            $this->logSecurityEvent('center.financials.settlement_approved', [
                'settlement_uuid' => $record->uuid,
                'center_teacher_uuid' => $teacher->uuid,
                'period_key' => $resolvedPeriod,
                'teacher_share_egp' => $record->teacher_share_egp,
            ], 'info', $record);

            return $record;
        });

        return [
            'id' => $settlement->uuid,
            'teacherId' => $teacher->uuid,
            'teacherNameAr' => $teacher->name_ar,
            'periodKey' => $settlement->period_key,
            'grossCollectedEgp' => (float) $settlement->gross_collected_egp,
            'settlementRate' => (float) $settlement->settlement_rate,
            'teacherShareEgp' => (float) $settlement->teacher_share_egp,
            'centerShareEgp' => (float) $settlement->center_share_egp,
            'status' => $settlement->status,
            'statusAr' => 'تم الصرف والتحويل',
            'approvedAt' => $settlement->approved_at?->toIso8601String(),
        ];
    }

    /**
     * List all outstanding student tuition dues across the center's groups.
     *
     * @return array<string, mixed>
     */
    public function listOutstandingDues(User $center, ?string $branchUuid = null): array
    {
        $branchId = null;
        if ($branchUuid && $branchUuid !== 'all') {
            $branchId = $this->branchService->findOwnedBranch($center, $branchUuid)->id;
        }

        $dues = GroupEnrollment::query()
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->where('due_amount_egp', '>', 0)
            ->whereHas('group', function ($q) use ($center, $branchId) {
                $q->where('center_id', $center->id)
                    ->when($branchId !== null, fn ($sub) => $sub->where('center_branch_id', $branchId));
            })
            ->with([
                'student:id,name,phone,student_code',
                'group:id,uuid,name_ar,subject_ar,center_name',
            ])
            ->orderByDesc('due_amount_egp')
            ->get();

        $items = $dues->map(function (GroupEnrollment $e) {
            $daysDue = $e->payment_due_since
                ? max(0, (int) $e->payment_due_since->diffInDays(now()))
                : 0;

            return [
                'id' => $e->uuid,
                'enrollmentId' => $e->uuid,
                'studentNameAr' => $e->student_name_override ?: ($e->student?->name ?? 'طالب'),
                'phone' => $e->student_phone_override ?: ($e->student?->phone ?? ''),
                'parentPhone' => $e->parent_phone ?? '',
                'groupId' => $e->group?->uuid,
                'groupNameAr' => $e->group?->name_ar ?? '',
                'subjectAr' => $e->group?->subject_ar ?? '',
                'paymentStatus' => $e->payment_status,
                'dueAmountEgp' => (float) $e->due_amount_egp,
                'dueSince' => $e->payment_due_since?->toDateString(),
                'daysDue' => $daysDue,
                'lastReminderAt' => $e->last_payment_reminder_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'totalOutstandingEgp' => round((float) $dues->sum('due_amount_egp'), 2),
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * Send a payment reminder for an overdue student enrollment owned by this center.
     *
     * @return array<string, mixed>
     */
    public function remindStudentDue(User $center, string $enrollmentUuid): array
    {
        $enrollment = $this->findOwnedEnrollment($center, $enrollmentUuid);

        $enrollment->update(['last_payment_reminder_at' => now()]);

        $this->logSecurityEvent('center.financials.due_reminder_sent', [
            'enrollment_uuid' => $enrollment->uuid,
            'group_uuid' => $enrollment->group?->uuid,
            'due_amount_egp' => (float) $enrollment->due_amount_egp,
        ], 'info', $enrollment);

        return [
            'enrollmentId' => $enrollment->uuid,
            'studentNameAr' => $enrollment->student_name_override ?: ($enrollment->student?->name ?? 'طالب'),
            'dueAmountEgp' => (float) $enrollment->due_amount_egp,
            'remindedAt' => $enrollment->last_payment_reminder_at?->toIso8601String(),
        ];
    }

    /**
     * Settle an overdue student enrollment balance in full.
     *
     * @return array<string, mixed>
     */
    public function markDuePaid(User $center, string $enrollmentUuid): array
    {
        $enrollment = $this->findOwnedEnrollment($center, $enrollmentUuid);
        $previousDue = (float) $enrollment->due_amount_egp;

        $enrollment->update([
            'payment_status' => 'paid',
            'due_amount_egp' => 0.00,
            'payment_due_since' => null,
        ]);

        $this->logSecurityEvent('center.financials.due_settled', [
            'enrollment_uuid' => $enrollment->uuid,
            'group_uuid' => $enrollment->group?->uuid,
            'settled_amount_egp' => $previousDue,
        ], 'info', $enrollment);

        return [
            'enrollmentId' => $enrollment->uuid,
            'paymentStatus' => 'paid',
            'settledAmountEgp' => $previousDue,
            'dueAmountEgp' => 0.0,
        ];
    }

    protected function findOwnedEnrollment(User $center, string $enrollmentUuid): GroupEnrollment
    {
        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('uuid', $enrollmentUuid)
            ->with(['group', 'student:id,name,phone'])
            ->first();

        if (! $enrollment || ! $enrollment->group) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        $enrollment->group->assertOwnedByCenter($center);

        return $enrollment;
    }

    /**
     * @return array{groupsCount: int, payingStudentsCount: int, grossCollectedEgp: float, settlementRate: float, teacherShareEgp: float, centerShareEgp: float}
     */
    protected function computeTeacherNumbers(CenterTeacher $teacher): array
    {
        $groups = $teacher->studyGroups ?? collect();
        $groupsCount = $groups->count();
        $payingStudents = 0;
        $gross = 0.0;

        foreach ($groups as $g) {
            $enrolled = $g->enrolledEnrollments ?? collect();
            $count = $enrolled->count();
            $payingStudents += $count;
            $expected = $count * (float) $g->price_egp;
            $dues = (float) $enrolled->sum('due_amount_egp');
            $gross += max(0.0, $expected - $dues);
        }

        $rate = (float) ($teacher->settlement_rate ?: 0.75);
        $grossRounded = round($gross, 2);
        $teacherShare = round($grossRounded * $rate, 2);
        $centerShare = round($grossRounded - $teacherShare, 2);

        return [
            'groupsCount' => $groupsCount,
            'payingStudentsCount' => $payingStudents,
            'grossCollectedEgp' => $grossRounded,
            'settlementRate' => $rate,
            'teacherShareEgp' => $teacherShare,
            'centerShareEgp' => $centerShare,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatExpense(CenterExpense $e): array
    {
        $cat = $e->category instanceof CenterExpenseCategory
            ? $e->category
            : (CenterExpenseCategory::tryFrom((string) $e->category) ?? CenterExpenseCategory::OTHER);

        return [
            'id' => $e->uuid,
            'uuid' => $e->uuid,
            'receiptNumber' => $e->receipt_number,
            'titleAr' => $e->title_ar,
            'titleEn' => $e->title_en ?: $e->title_ar,
            'category' => $cat->value,
            'categoryAr' => $cat->labelAr(),
            'categoryEn' => $cat->labelEn(),
            'branchId' => $e->branch?->uuid,
            'branchNameAr' => $e->branch?->name_ar ?? 'الفرع الرئيسي',
            'amountEgp' => (float) $e->amount_egp,
            'date' => $e->expense_date?->toDateString(),
            'status' => $e->status,
            'notes' => $e->notes,
        ];
    }

    protected function resolveOwnedBranchId(User $center, mixed $branchUuid): ?int
    {
        if (empty($branchUuid) || $branchUuid === 'all') {
            return CenterBranch::forCenter($center->id)->orderByDesc('is_main')->value('id');
        }

        /** @var CenterBranch|null $branch */
        $branch = CenterBranch::where('uuid', (string) $branchUuid)->first();
        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => [__('messages.center.branch_not_found')],
            ]);
        }

        $branch->assertOwnedByCenter($center);

        return $branch->id;
    }

    protected function normalizePeriodKey(?string $periodKey): string
    {
        if ($periodKey && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', trim($periodKey))) {
            return trim($periodKey);
        }

        return now()->format('Y-m');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodBounds(string $periodKey): array
    {
        $start = Carbon::createFromFormat('Y-m-d', "{$periodKey}-01")->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}
