<?php

namespace App\Services\V1\Admin;

use App\Enums\BillingPeriod;
use App\Enums\CenterPlanTier;
use App\Enums\DirectPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PayoutRequestStatus;
use App\Enums\TeacherPlanTier;
use App\Models\CenterInvoice;
use App\Models\CenterProfile;
use App\Models\CenterSubscription;
use App\Models\DiscountCoupon;
use App\Models\PlatformExpense;
use App\Models\PlatformPayoutRequest;
use App\Models\PlatformSubscriptionPlan;
use App\Models\StudentDirectPayment;
use App\Models\StudentInvoice;
use App\Models\StudentSubscription;
use App\Models\TeacherInvoice;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\SubscriptionReceiptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AdminFinancialService extends BaseService
{
    public const EXPENSE_CATEGORIES = [
        'hosting',
        'ai',
        'marketing',
        'operations',
        'salaries',
        'other',
    ];

    public function __construct(
        protected PlatformSettingsService $settingsService,
        protected AdminDashboardService $dashboardService,
        protected SubscriptionReceiptService $receiptService
    ) {}

    /**
     * Global Financial KPIs Overview: Real Platform Gross Revenue, Per-Role Revenue (Teacher / Student / Center),
     * Manual & AI Costs, Net Profit, Active Subscribers, and Recent Verified Invoices.
     *
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        $cacheKey = $this->settingsService->cacheKey('admin:financials:overview');

        return Cache::remember($cacheKey, now()->addSeconds(60), function (): array {
            $commissionRate = ((float) $this->settingsService->get('platform_commission_percent', 20.0)) / 100.0;

            // 1. Paid subscription invoices per role (100% real collected platform revenue)
            $teacherSubStats = TeacherInvoice::query()
                ->where('status', InvoiceStatus::PAID->value)
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(final_amount_egp), 0) as total')
                ->first();
            $studentSubStats = StudentInvoice::query()
                ->where('status', InvoiceStatus::PAID->value)
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(final_amount_egp), 0) as total')
                ->first();
            $centerSubStats = CenterInvoice::query()
                ->where('status', InvoiceStatus::PAID->value)
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(final_amount_egp), 0) as total')
                ->first();

            $teacherSubRev = round((float) ($teacherSubStats?->total ?? 0), 2);
            $studentSubRev = round((float) ($studentSubStats?->total ?? 0), 2);
            $centerSubRev = round((float) ($centerSubStats?->total ?? 0), 2);

            $teacherInvoicesCount = (int) ($teacherSubStats?->cnt ?? 0);
            $studentInvoicesCount = (int) ($studentSubStats?->cnt ?? 0);
            $centerInvoicesCount = (int) ($centerSubStats?->cnt ?? 0);

            $subscriptionRevenue = round($teacherSubRev + $studentSubRev + $centerSubRev, 2);

            // 2. Confirmed direct student-to-teacher payments & platform commission cut
            $directPaymentsVolume = round((float) StudentDirectPayment::query()
                ->where('status', DirectPaymentStatus::CONFIRMED->value)
                ->sum('amount_egp'), 2);
            $directCommissionEgp = round($directPaymentsVolume * $commissionRate, 2);

            // 3. Gross Platform Revenue & Per-Role Revenue Attribution
            $teacherPlatformRev = round($teacherSubRev + $directCommissionEgp, 2);
            $studentPlatformRev = $studentSubRev;
            $centerPlatformRev = $centerSubRev;

            $grossPlatformRevenueEgp = round($subscriptionRevenue + $directCommissionEgp, 2);
            $gmvEgp = round($subscriptionRevenue + $directPaymentsVolume, 2);

            // 4. Active paid subscribers per role
            $now = now();
            $activeTeachersPaid = (int) TeacherSubscription::query()
                ->where('status', 'active')
                ->where('plan_tier', '!=', 'free')
                ->where(fn ($q) => $q->whereNull('renews_at')->orWhere('renews_at', '>', $now))
                ->distinct('teacher_id')
                ->count('teacher_id');

            $activeStudentsPaid = (int) StudentSubscription::query()
                ->where('status', 'active')
                ->where('plan_tier', '!=', 'free')
                ->where(fn ($q) => $q->whereNull('renews_at')->orWhere('renews_at', '>', $now))
                ->distinct('student_id')
                ->count('student_id');

            $activeCentersPaid = (int) CenterSubscription::query()
                ->where('status', 'active')
                ->where('plan_tier', '!=', 'free')
                ->where(fn ($q) => $q->whereNull('renews_at')->orWhere('renews_at', '>', $now))
                ->distinct('center_id')
                ->count('center_id');

            // 5. Costs (Manual Admin-Entered Costs + Auto AI Infrastructure Estimate) & Real Net Profit
            $manualExpenseStats = PlatformExpense::query()
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount_egp), 0) as total')
                ->first();
            $manualExpensesEgp = round((float) ($manualExpenseStats?->total ?? 0), 2);
            $manualExpensesCount = (int) ($manualExpenseStats?->cnt ?? 0);

            $aiEcon = $this->dashboardService->computeAiEconomics();
            $aiCostEgp = round((float) ($aiEcon['aiProcessingCostEgp'] ?? 0), 2);

            $totalCostsEgp = round($manualExpensesEgp + $aiCostEgp, 2);
            $netProfitEgp = round($grossPlatformRevenueEgp - $totalCostsEgp, 2);
            $profitMarginPercent = $grossPlatformRevenueEgp > 0
                ? round(($netProfitEgp / $grossPlatformRevenueEgp) * 100, 1)
                : 0.0;

            // 6. Payouts & Coupons counters
            $settledPayoutsEgp = round((float) PlatformPayoutRequest::query()
                ->where('status', PayoutRequestStatus::COMPLETED->value)
                ->sum('amount_egp'), 2);

            $pendingPayoutsEgp = round((float) PlatformPayoutRequest::query()
                ->where('status', PayoutRequestStatus::PENDING->value)
                ->sum('amount_egp'), 2);

            $pendingPayoutsCount = (int) PlatformPayoutRequest::query()
                ->where('status', PayoutRequestStatus::PENDING->value)
                ->count();

            $pendingSubRequestsCount = (int) StudentSubscription::query()->where('status', 'pending_review')->count()
                + (int) TeacherSubscription::query()->where('status', 'pending_review')->count()
                + (int) CenterSubscription::query()->where('status', 'pending_review')->count();

            $activeCouponsCount = (int) DiscountCoupon::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->count();

            $shareOf = static fn (float $part, float $whole): float => $whole > 0
                ? round(($part / $whole) * 100, 1)
                : 0.0;

            return [
                'gmvEgp' => $gmvEgp,
                'grossPlatformRevenueEgp' => $grossPlatformRevenueEgp,
                'netProfitEgp' => $netProfitEgp,
                'profitMarginPercent' => $profitMarginPercent,
                'totalCostsEgp' => $totalCostsEgp,
                'manualExpensesEgp' => $manualExpensesEgp,
                'manualExpensesCount' => $manualExpensesCount,
                'subscriptionRevenueEgp' => $subscriptionRevenue,
                'subscriptionBreakdown' => [
                    'studentsEgp' => $studentSubRev,
                    'teachersEgp' => $teacherSubRev,
                    'centersEgp' => $centerSubRev,
                ],
                'roleBreakdown' => [
                    'teacher' => [
                        'revenueEgp' => $teacherPlatformRev,
                        'subscriptionRevenueEgp' => $teacherSubRev,
                        'directCommissionEgp' => $directCommissionEgp,
                        'paidInvoicesCount' => $teacherInvoicesCount,
                        'activePaidSubscribersCount' => $activeTeachersPaid,
                        'sharePercent' => $shareOf($teacherPlatformRev, $grossPlatformRevenueEgp),
                    ],
                    'student' => [
                        'revenueEgp' => $studentPlatformRev,
                        'subscriptionRevenueEgp' => $studentSubRev,
                        'directCommissionEgp' => 0.0,
                        'paidInvoicesCount' => $studentInvoicesCount,
                        'activePaidSubscribersCount' => $activeStudentsPaid,
                        'sharePercent' => $shareOf($studentPlatformRev, $grossPlatformRevenueEgp),
                    ],
                    'center' => [
                        'revenueEgp' => $centerPlatformRev,
                        'subscriptionRevenueEgp' => $centerSubRev,
                        'directCommissionEgp' => 0.0,
                        'paidInvoicesCount' => $centerInvoicesCount,
                        'activePaidSubscribersCount' => $activeCentersPaid,
                        'sharePercent' => $shareOf($centerPlatformRev, $grossPlatformRevenueEgp),
                    ],
                ],
                'directPaymentsVolumeEgp' => $directPaymentsVolume,
                'directCommissionEgp' => $directCommissionEgp,
                'platformCommissionPercent' => round($commissionRate * 100, 2),
                'platformCommissionEgp' => $grossPlatformRevenueEgp,
                'settledPayoutsEgp' => $settledPayoutsEgp,
                'pendingPayoutsEgp' => $pendingPayoutsEgp,
                'pendingPayoutsCount' => $pendingPayoutsCount,
                'pendingSubscriptionRequestsCount' => $pendingSubRequestsCount,
                'aiInfrastructureCostEgp' => $aiCostEgp,
                'aiCostPerMinuteEgp' => $aiEcon['costPerMinuteEgp'] ?? 0.42,
                'activeCouponsCount' => $activeCouponsCount,
                'recentInvoices' => $this->loadRecentPaidInvoices(15),
            ];
        });
    }

    /**
     * Load recent verified paid subscription invoices across Teachers, Students, and Centers (zero N+1).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadRecentPaidInvoices(int $limit = 15): array
    {
        $tRows = TeacherInvoice::query()
            ->where('status', InvoiceStatus::PAID->value)
            ->latest('paid_at')
            ->limit($limit)
            ->get(['invoice_number', 'teacher_id', 'description_ar', 'final_amount_egp', 'payment_method', 'paid_at', 'created_at']);

        $sRows = StudentInvoice::query()
            ->where('status', InvoiceStatus::PAID->value)
            ->latest('paid_at')
            ->limit($limit)
            ->get(['invoice_number', 'student_id', 'description_ar', 'final_amount_egp', 'payment_method', 'paid_at', 'created_at']);

        $cRows = CenterInvoice::query()
            ->where('status', InvoiceStatus::PAID->value)
            ->latest('paid_at')
            ->limit($limit)
            ->get(['invoice_number', 'center_id', 'description_ar', 'final_amount_egp', 'payment_method', 'paid_at', 'created_at']);

        $userIds = array_values(array_unique(array_merge(
            $tRows->pluck('teacher_id')->all(),
            $sRows->pluck('student_id')->all(),
            $cRows->pluck('center_id')->all()
        )));

        $usersMap = ! empty($userIds)
            ? User::query()->whereIn('id', $userIds)->pluck('name', 'id')->all()
            : [];

        $merged = [];
        foreach ($tRows as $r) {
            $pm = is_object($r->payment_method) ? $r->payment_method->value : (string) $r->payment_method;
            $ts = $r->paid_at ?: $r->created_at;
            $merged[] = [
                'invoiceNumber' => $r->invoice_number ?: 'T-INV',
                'role' => 'teacher',
                'payerName' => $usersMap[$r->teacher_id] ?? ('معلم #'.$r->teacher_id),
                'descriptionAr' => $r->description_ar,
                'amountEgp' => round((float) $r->final_amount_egp, 2),
                'paymentMethod' => $pm,
                'paidAt' => $ts?->toIso8601String(),
                'paidDate' => $ts?->toDateString(),
                '_sortTs' => $ts?->getTimestamp() ?? 0,
            ];
        }

        foreach ($sRows as $r) {
            $pm = is_object($r->payment_method) ? $r->payment_method->value : (string) $r->payment_method;
            $ts = $r->paid_at ?: $r->created_at;
            $merged[] = [
                'invoiceNumber' => $r->invoice_number ?: 'S-INV',
                'role' => 'student',
                'payerName' => $usersMap[$r->student_id] ?? ('طالب #'.$r->student_id),
                'descriptionAr' => $r->description_ar,
                'amountEgp' => round((float) $r->final_amount_egp, 2),
                'paymentMethod' => $pm,
                'paidAt' => $ts?->toIso8601String(),
                'paidDate' => $ts?->toDateString(),
                '_sortTs' => $ts?->getTimestamp() ?? 0,
            ];
        }

        foreach ($cRows as $r) {
            $pm = is_object($r->payment_method) ? $r->payment_method->value : (string) $r->payment_method;
            $ts = $r->paid_at ?: $r->created_at;
            $merged[] = [
                'invoiceNumber' => $r->invoice_number ?: 'C-INV',
                'role' => 'center',
                'payerName' => $usersMap[$r->center_id] ?? ('سنتر #'.$r->center_id),
                'descriptionAr' => $r->description_ar,
                'amountEgp' => round((float) $r->final_amount_egp, 2),
                'paymentMethod' => $pm,
                'paidAt' => $ts?->toIso8601String(),
                'paidDate' => $ts?->toDateString(),
                '_sortTs' => $ts?->getTimestamp() ?? 0,
            ];
        }

        usort($merged, static fn (array $a, array $b): int => $b['_sortTs'] <=> $a['_sortTs']);

        return array_map(static function (array $row): array {
            unset($row['_sortTs']);

            return $row;
        }, array_slice($merged, 0, $limit));
    }

    /**
     * Paginated & searchable list of verified paid subscription invoices across Teachers, Students, and Centers.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listPaidInvoices(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 10), 1), 50);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $role = isset($filters['role']) ? strtolower(trim((string) $filters['role'])) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $validRoles = ['teacher', 'student', 'center'];
        $rolesToQuery = ($role && in_array($role, $validRoles, true)) ? [$role] : $validRoles;

        $paidStatus = InvoiceStatus::PAID->value;
        $usersTable = (new User)->getTable();
        $safeSearch = ($search !== null && $search !== '') ? '%'.addcslashes($search, '%_\\').'%' : null;

        $roleSpecs = [
            'teacher' => ['table' => (new TeacherInvoice)->getTable(), 'fk' => 'teacher_id', 'fallback' => 'T-INV', 'prefix' => 'معلم #'],
            'student' => ['table' => (new StudentInvoice)->getTable(), 'fk' => 'student_id', 'fallback' => 'S-INV', 'prefix' => 'طالب #'],
            'center' => ['table' => (new CenterInvoice)->getTable(), 'fk' => 'center_id', 'fallback' => 'C-INV', 'prefix' => 'سنتر #'],
        ];

        $unionQuery = null;
        foreach ($rolesToQuery as $rKey) {
            $spec = $roleSpecs[$rKey];
            $branch = DB::table($spec['table'].' as inv')
                ->leftJoin($usersTable.' as u', 'u.id', '=', 'inv.'.$spec['fk'])
                ->where('inv.status', $paidStatus)
                ->selectRaw(
                    'inv.invoice_number, ? as role, inv.'.$spec['fk'].' as user_id, u.name as payer_name, inv.description_ar, inv.final_amount_egp, inv.payment_method, COALESCE(inv.paid_at, inv.created_at) as effective_paid_at',
                    [$rKey]
                );

            if ($safeSearch !== null) {
                $branch->where(function ($q) use ($safeSearch): void {
                    $q->where('u.name', 'like', $safeSearch)
                        ->orWhere('inv.invoice_number', 'like', $safeSearch);
                });
            }

            $unionQuery = $unionQuery === null ? $branch : $unionQuery->unionAll($branch);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = DB::query()
            ->fromSub($unionQuery, 'paid_invoices')
            ->orderByDesc('effective_paid_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(static function (object $row) use ($roleSpecs): array {
            $rKey = (string) ($row->role ?? 'student');
            $spec = $roleSpecs[$rKey] ?? $roleSpecs['student'];
            $paidAt = $row->effective_paid_at ? Carbon::parse($row->effective_paid_at) : null;

            return [
                'invoiceNumber' => $row->invoice_number ?: $spec['fallback'],
                'role' => $rKey,
                'payerName' => $row->payer_name ?: ($spec['prefix'].($row->user_id ?? '')),
                'descriptionAr' => $row->description_ar,
                'amountEgp' => round((float) $row->final_amount_egp, 2),
                'paymentMethod' => (string) ($row->payment_method ?? ''),
                'paidAt' => $paidAt?->toIso8601String(),
                'paidDate' => $paidAt?->toDateString(),
            ];
        })->values()->all();

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /* =========================================================================
       MANUAL PLATFORM COSTS & EXPENSES LEDGER (AMOUNT + NOTE PER ENTRY)
       ========================================================================= */

    /**
     * List manual cost/expense entries with category totals.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listExpenses(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 50), 1), 100);
        $category = isset($filters['category']) ? strtolower(trim((string) $filters['category'])) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $query = PlatformExpense::query()
            ->with(['creator:id,name', 'updater:id,name'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($category && $category !== 'all' && in_array($category, self::EXPENSE_CATEGORIES, true)) {
            $query->where('category', $category);
        }

        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $query->where(function (Builder $q) use ($safe): void {
                $q->where('title', 'like', "%{$safe}%")
                    ->orWhere('note', 'like', "%{$safe}%");
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        $byCategory = PlatformExpense::query()
            ->select('category', DB::raw('COALESCE(SUM(amount_egp), 0) as total'))
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        $totalEgp = round(array_sum($byCategory), 2);

        return [
            'summary' => [
                'totalEgp' => $totalEgp,
                'count' => (int) PlatformExpense::query()->count(),
                'byCategory' => $byCategory,
            ],
            'items' => collect($paginator->items())
                ->map(fn (PlatformExpense $e) => $e->toFrontendArray())
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * Record a manual platform cost entry with its note and immediately recalculate Net Profit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createExpense(User $admin, array $data): array
    {
        $normalized = $this->normalizeExpensePayload($data, null);

        /** @var PlatformExpense $expense */
        $expense = $this->transaction(function () use ($admin, $normalized): PlatformExpense {
            $model = new PlatformExpense($normalized);
            $model->forceFill([
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
            $model->save();

            $this->logSecurityEvent('admin.expense.created', [
                'admin_id' => $admin->id,
                'expense_uuid' => $model->uuid,
                'title' => $model->title,
                'amount_egp' => $model->amount_egp,
                'category' => $model->category,
            ], 'info', $model);

            return $model;
        });

        $this->settingsService->bumpCacheEpoch();

        return $expense->fresh(['creator:id,name', 'updater:id,name'])->toFrontendArray();
    }

    /**
     * Update an existing manual cost entry (amount, note, category, or date).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateExpense(User $admin, string $uuid, array $data): array
    {
        /** @var PlatformExpense $expense */
        $expense = PlatformExpense::query()->where('uuid', $uuid)->firstOrFail();

        $beforeAmount = (float) $expense->amount_egp;
        $normalized = $this->normalizeExpensePayload($data, $expense);

        $this->transaction(function () use ($admin, $expense, $normalized, $beforeAmount): void {
            $expense->fill($normalized);
            $expense->forceFill(['updated_by' => $admin->id])->save();

            $this->logSecurityEvent('admin.expense.updated', [
                'admin_id' => $admin->id,
                'expense_uuid' => $expense->uuid,
                'amount_before' => $beforeAmount,
                'amount_after' => (float) $expense->amount_egp,
            ], 'info', $expense);
        });

        $this->settingsService->bumpCacheEpoch();

        return $expense->fresh(['creator:id,name', 'updater:id,name'])->toFrontendArray();
    }

    /**
     * Soft-delete a manual cost entry with audit log.
     */
    public function deleteExpense(User $admin, string $uuid): void
    {
        /** @var PlatformExpense $expense */
        $expense = PlatformExpense::query()->where('uuid', $uuid)->firstOrFail();

        $this->transaction(function () use ($admin, $expense): void {
            $amount = (float) $expense->amount_egp;
            $title = $expense->title;
            $expense->delete();

            $this->logSecurityEvent('admin.expense.deleted', [
                'admin_id' => $admin->id,
                'expense_uuid' => $expense->uuid,
                'title' => $title,
                'amount_egp' => $amount,
            ], 'warning', $expense);
        });

        $this->settingsService->bumpCacheEpoch();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeExpensePayload(array $data, ?PlatformExpense $existing): array
    {
        $rawTitle = $data['title'] ?? $existing?->title ?? '';
        $title = mb_substr(trim(strip_tags((string) $rawTitle)), 0, 180);
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => ['يرجى إدخال اسم أو عنوان بند التكلفة.'],
            ]);
        }

        $rawAmount = $data['amount_egp'] ?? $data['amountEgp'] ?? $existing?->amount_egp ?? null;
        if ($rawAmount === null || ! is_numeric($rawAmount) || (float) $rawAmount <= 0 || (float) $rawAmount > 50000000) {
            throw ValidationException::withMessages([
                'amountEgp' => ['يرجى إدخال قيمة تكلفة صحيحة أكبر من صفر.'],
            ]);
        }

        $category = strtolower(trim((string) ($data['category'] ?? $existing?->category ?? 'operations')));
        if (! in_array($category, self::EXPENSE_CATEGORIES, true)) {
            $category = 'other';
        }

        $hasNote = array_key_exists('note', $data);
        $note = $hasNote
            ? mb_substr(trim(strip_tags((string) $data['note'])), 0, 2000)
            : ($existing?->note ?? '');

        $expenseDate = $data['expense_date'] ?? $data['expenseDate'] ?? ($existing?->expense_date?->toDateString() ?? now()->toDateString());

        return [
            'title' => $title,
            'category' => $category,
            'amount_egp' => round((float) $rawAmount, 2),
            'note' => $note !== '' ? $note : null,
            'expense_date' => $expenseDate,
        ];
    }

    /* =========================================================================
       SUBSCRIPTION PLANS & PRICING MANAGEMENT (DB-SYNCED)
       ========================================================================= */

    /**
     * List platform subscription plans (seeds authoritative defaults on first read if table is empty,
     * and enriches each plan with live active subscriber counts).
     *
     * @return list<array<string, mixed>>
     */
    public function listPlans(?string $targetRole = null, bool $onlyActive = false): array
    {
        if (PlatformSubscriptionPlan::query()->withTrashed()->count() === 0) {
            $this->seedDefaultPlans();
        } else {
            $this->upgradeUnmodifiedSeededPlanFeaturesOnce();
        }

        $query = PlatformSubscriptionPlan::query()
            ->orderBy('target_role')
            ->orderBy('display_order')
            ->orderBy('price_egp');

        if ($targetRole && in_array($targetRole, ['center', 'teacher', 'student'], true)) {
            $query->where('target_role', $targetRole);
        }

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        $subscriberCounts = $this->computeActiveSubscribersByRoleAndTier();

        return $query->get()
            ->map(function (PlatformSubscriptionPlan $p) use ($subscriberCounts): array {
                $arr = $p->toFrontendArray();
                $arr['activeSubscribersCount'] = (int) ($subscriberCounts[$p->target_role][$p->tier_key] ?? 0);

                return $arr;
            })
            ->values()
            ->all();
    }

    /**
     * Compute active subscribers grouped by [role][tier_key] in 3 lightweight aggregate queries.
     *
     * @return array<string, array<string, int>>
     */
    protected function computeActiveSubscribersByRoleAndTier(): array
    {
        $now = now();

        $studentTiers = StudentSubscription::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('renews_at')->orWhere('renews_at', '>', $now))
            ->select('plan_tier', DB::raw('COUNT(DISTINCT student_id) as cnt'))
            ->groupBy('plan_tier')
            ->pluck('cnt', 'plan_tier')
            ->map(fn ($v) => (int) $v)
            ->all();

        $teacherTiers = TeacherSubscription::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('renews_at')->orWhere('renews_at', '>', $now))
            ->select('plan_tier', DB::raw('COUNT(DISTINCT teacher_id) as cnt'))
            ->groupBy('plan_tier')
            ->pluck('cnt', 'plan_tier')
            ->map(fn ($v) => (int) $v)
            ->all();

        $centerTiers = CenterSubscription::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('renews_at')->orWhere('renews_at', '>', $now))
            ->select('plan_tier', DB::raw('COUNT(DISTINCT center_id) as cnt'))
            ->groupBy('plan_tier')
            ->pluck('cnt', 'plan_tier')
            ->map(fn ($v) => (int) $v)
            ->all();

        return [
            'student' => $studentTiers,
            'teacher' => $teacherTiers,
            'center' => $centerTiers,
        ];
    }

    /**
     * Cached public pricing plans catalog for PricingPage.
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicPlans(?string $targetRole = null): array
    {
        $roleKey = ($targetRole && in_array($targetRole, ['center', 'teacher', 'student'], true))
            ? $targetRole
            : 'all';

        $cacheKey = $this->settingsService->cacheKey("public:pricing:{$roleKey}");

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($roleKey): array {
            return $this->listPlans($roleKey === 'all' ? null : $roleKey, true);
        });
    }

    /**
     * Normalize promotional discount & temporary free pricing fields for a plan create/update.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sanitizePlanPromotionFields(array $data, ?PlatformSubscriptionPlan $existing = null): array
    {
        $price = array_key_exists('price_egp', $data) ? (float) $data['price_egp'] : (float) ($existing?->price_egp ?? 0);
        $termPrice = array_key_exists('term_price_egp', $data)
            ? ($data['term_price_egp'] !== null ? (float) $data['term_price_egp'] : null)
            : ($existing?->term_price_egp !== null ? (float) $existing->term_price_egp : ($price > 0 ? round($price * 4, 2) : 0.0));
        $annualPrice = array_key_exists('annual_price_egp', $data)
            ? (float) $data['annual_price_egp']
            : (float) ($existing?->annual_price_egp ?? 0);

        $origMonthly = array_key_exists('original_price_egp', $data)
            ? ($data['original_price_egp'] !== null ? (float) $data['original_price_egp'] : null)
            : ($existing?->original_price_egp !== null ? (float) $existing->original_price_egp : null);
        $origTerm = array_key_exists('original_term_price_egp', $data)
            ? ($data['original_term_price_egp'] !== null ? (float) $data['original_term_price_egp'] : null)
            : ($existing?->original_term_price_egp !== null ? (float) $existing->original_term_price_egp : null);
        $origAnnual = array_key_exists('original_annual_price_egp', $data)
            ? ($data['original_annual_price_egp'] !== null ? (float) $data['original_annual_price_egp'] : null)
            : ($existing?->original_annual_price_egp !== null ? (float) $existing->original_annual_price_egp : null);

        // Ensure compare-at prices are strictly greater than effective prices (otherwise clear them cleanly)
        if ($origMonthly !== null && $origMonthly <= $price) {
            $origMonthly = null;
        }
        if ($origTerm !== null && $termPrice !== null && $origTerm <= $termPrice) {
            $origTerm = null;
        }
        if ($origAnnual !== null && $origAnnual <= $annualPrice) {
            $origAnnual = null;
        }

        $computedDiscount = PlatformSubscriptionPlan::computeDiscountPercent($origMonthly, $price);
        if ($computedDiscount === null && array_key_exists('discount_percent', $data) && $data['discount_percent'] !== null && $origMonthly !== null) {
            $computedDiscount = round((float) $data['discount_percent'], 1);
        }

        $freePromoEnabled = array_key_exists('free_promo_enabled', $data)
            ? (bool) $data['free_promo_enabled']
            : (bool) ($existing?->free_promo_enabled ?? false);

        $freePromoUntil = null;
        if ($freePromoEnabled) {
            if (array_key_exists('free_promo_until', $data)) {
                $freePromoUntil = $data['free_promo_until'] ? Carbon::parse($data['free_promo_until']) : null;
            } else {
                $freePromoUntil = $existing?->free_promo_until;
            }
        }

        $freeLabelAr = array_key_exists('free_promo_label_ar', $data)
            ? ($data['free_promo_label_ar'] !== null ? trim(strip_tags((string) $data['free_promo_label_ar'])) : null)
            : $existing?->free_promo_label_ar;
        $freeLabelEn = array_key_exists('free_promo_label_en', $data)
            ? ($data['free_promo_label_en'] !== null ? trim(strip_tags((string) $data['free_promo_label_en'])) : null)
            : $existing?->free_promo_label_en;

        return [
            'price_egp' => round($price, 2),
            'original_price_egp' => $origMonthly !== null ? round($origMonthly, 2) : null,
            'term_price_egp' => $termPrice !== null ? round($termPrice, 2) : null,
            'original_term_price_egp' => $origTerm !== null ? round($origTerm, 2) : null,
            'annual_price_egp' => round($annualPrice, 2),
            'original_annual_price_egp' => $origAnnual !== null ? round($origAnnual, 2) : null,
            'discount_percent' => $computedDiscount,
            'free_promo_enabled' => $freePromoEnabled,
            'free_promo_until' => $freePromoUntil,
            'free_promo_label_ar' => $freePromoEnabled ? ($freeLabelAr ?: 'مجانية لفترة محدودة') : null,
            'free_promo_label_en' => $freePromoEnabled ? ($freeLabelEn ?: 'Free for a Limited Time') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPlan(User $admin, array $data): array
    {
        $promoFields = $this->sanitizePlanPromotionFields($data, null);

        $plan = new PlatformSubscriptionPlan(array_merge([
            'slug' => $data['slug'] ?? null,
            'target_role' => $data['target_role'],
            'tier_key' => $data['tier_key'] ?? 'custom',
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? $data['name_ar'],
            'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
            'commission_percent' => $data['target_role'] === 'teacher' && isset($data['commission_percent'])
                ? (float) $data['commission_percent']
                : null,
            'badge_ar' => $data['badge_ar'] ?? null,
            'badge_en' => $data['badge_en'] ?? ($data['badge_ar'] ?? null),
            'is_popular' => (bool) ($data['is_popular'] ?? false),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'features_ar' => $data['features_ar'] ?? ['اشتراك معتمد في منصة متفوق'],
            'features_en' => $data['features_en'] ?? ['Verified Motafawweq platform subscription'],
            'display_order' => (int) ($data['display_order'] ?? 0),
        ], $promoFields));

        $plan->forceFill(['updated_by' => $admin->id])->save();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.plan.created', [
            'admin_id' => $admin->id,
            'plan_uuid' => $plan->uuid,
            'target_role' => $plan->target_role,
            'price_egp' => $plan->price_egp,
            'free_promo_enabled' => $plan->free_promo_enabled,
        ], 'info', $plan);

        return $plan->toFrontendArray();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updatePlan(User $admin, string $uuid, array $data): array
    {
        /** @var PlatformSubscriptionPlan $plan */
        $plan = PlatformSubscriptionPlan::query()->where('uuid', $uuid)->firstOrFail();

        $beforePrice = (float) $plan->price_egp;
        $allowed = [
            'target_role', 'tier_key', 'name_ar', 'name_en', 'billing_cycle',
            'commission_percent', 'badge_ar', 'badge_en', 'is_popular', 'is_active',
            'features_ar', 'features_en', 'display_order',
        ];

        $promoFields = $this->sanitizePlanPromotionFields($data, $plan);
        $plan->fill(array_merge(array_intersect_key($data, array_flip($allowed)), $promoFields));
        $plan->forceFill(['updated_by' => $admin->id])->save();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.plan.updated', [
            'admin_id' => $admin->id,
            'plan_uuid' => $plan->uuid,
            'price_before' => $beforePrice,
            'price_after' => (float) $plan->price_egp,
            'original_price_egp' => $plan->original_price_egp,
            'discount_percent' => $plan->discount_percent,
            'free_promo_enabled' => (bool) $plan->free_promo_enabled,
            'free_promo_until' => $plan->free_promo_until?->toIso8601String(),
        ], 'warning', $plan);

        return $plan->fresh()->toFrontendArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function togglePlanActive(User $admin, string $uuid): array
    {
        /** @var PlatformSubscriptionPlan $plan */
        $plan = PlatformSubscriptionPlan::query()->where('uuid', $uuid)->firstOrFail();

        $plan->forceFill([
            'is_active' => ! (bool) $plan->is_active,
            'updated_by' => $admin->id,
        ])->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.plan.toggled', [
            'admin_id' => $admin->id,
            'plan_uuid' => $plan->uuid,
            'is_active' => (bool) $plan->is_active,
        ], 'info', $plan);

        return $plan->fresh()->toFrontendArray();
    }

    public function deletePlan(User $admin, string $uuid): void
    {
        /** @var PlatformSubscriptionPlan $plan */
        $plan = PlatformSubscriptionPlan::query()->where('uuid', $uuid)->firstOrFail();

        $plan->delete();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.plan.deleted', [
            'admin_id' => $admin->id,
            'plan_uuid' => $plan->uuid,
            'slug' => $plan->slug,
        ], 'warning', $plan);
    }

    /**
     * Atomically reset platform subscription plans to authoritative platform defaults.
     *
     * @return list<array<string, mixed>>
     */
    public function resetPlansToDefault(User $admin): array
    {
        $this->transaction(function () use ($admin): void {
            PlatformSubscriptionPlan::query()->withTrashed()->forceDelete();
            $this->seedDefaultPlans($admin->id);
        });

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.plans.reset_defaults', [
            'admin_id' => $admin->id,
        ], 'warning');

        return $this->listPlans();
    }

    /* =========================================================================
       SUPER ADMIN SUBSCRIPTION APPROVAL QUEUE (ALL ROLES: STUDENT / TEACHER / CENTER)
       ========================================================================= */

    /**
     * List subscription requests across Student, Teacher, and Center roles with receipt URLs and summary counters.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listSubscriptionRequests(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $role = isset($filters['role']) ? strtolower(trim((string) $filters['role'])) : null;
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : 'pending_review';
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $validRoles = ['student', 'teacher', 'center'];
        $rolesToQuery = ($role && in_array($role, $validRoles, true)) ? [$role] : $validRoles;

        $roleSpecs = [
            'student' => ['table' => (new StudentSubscription)->getTable(), 'fk' => 'student_id'],
            'teacher' => ['table' => (new TeacherSubscription)->getTable(), 'fk' => 'teacher_id'],
            'center' => ['table' => (new CenterSubscription)->getTable(), 'fk' => 'center_id'],
        ];

        $usersTable = (new User)->getTable();
        $safeSearch = ($search !== null && $search !== '') ? '%'.addcslashes($search, '%_\\').'%' : null;

        $unionQuery = null;
        foreach ($rolesToQuery as $rKey) {
            $spec = $roleSpecs[$rKey];
            $branch = DB::table($spec['table'].' as sub')
                ->leftJoin($usersTable.' as u', 'u.id', '=', 'sub.'.$spec['fk'])
                ->leftJoin($usersTable.' as rev', 'rev.id', '=', 'sub.reviewed_by')
                ->where('sub.plan_tier', '!=', 'free')
                ->selectRaw(
                    '? as role, sub.uuid, sub.'.$spec['fk'].' as user_id, u.name as user_name, u.email as user_email, u.phone as user_phone, '
                    .'sub.plan_tier, sub.plan_name_ar, sub.plan_name_en, sub.billing_period, sub.base_amount_egp, sub.discount_amount_egp, '
                    .'sub.amount_egp, sub.coupon_code, sub.payment_method, sub.transaction_reference, sub.sender_reference, '
                    .'sub.receipt_path, sub.receipt_original_name, sub.receipt_mime_type, sub.is_free_promo, '
                    .'sub.status, sub.rejection_reason, sub.starts_at, sub.renews_at, sub.reviewed_at, rev.name as reviewer_name, sub.created_at',
                    [$rKey]
                );

            if ($status && $status !== 'all') {
                if ($status === 'approved') {
                    $branch->whereIn('sub.status', ['active', 'superseded', 'expired']);
                } else {
                    $branch->where('sub.status', $status);
                }
            }

            if ($safeSearch !== null) {
                $branch->where(function ($q) use ($safeSearch): void {
                    $q->where('u.name', 'like', $safeSearch)
                        ->orWhere('u.email', 'like', $safeSearch)
                        ->orWhere('u.phone', 'like', $safeSearch)
                        ->orWhere('sub.transaction_reference', 'like', $safeSearch)
                        ->orWhere('sub.sender_reference', 'like', $safeSearch)
                        ->orWhere('sub.plan_name_ar', 'like', $safeSearch);
                });
            }

            $unionQuery = $unionQuery === null ? $branch : $unionQuery->unionAll($branch);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = DB::query()
            ->fromSub($unionQuery, 'subscription_requests')
            ->orderByRaw("CASE WHEN status = 'pending_review' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function (object $row): array {
            $rKey = (string) $row->role;
            $pmEnum = PaymentMethod::tryFrom((string) ($row->payment_method ?? ''));
            $createdAt = $row->created_at ? Carbon::parse($row->created_at) : null;
            $reviewedAt = $row->reviewed_at ? Carbon::parse($row->reviewed_at) : null;

            return [
                'id' => (string) $row->uuid,
                'role' => $rKey,
                'userId' => (int) ($row->user_id ?? 0),
                'userName' => (string) ($row->user_name ?? ''),
                'userEmail' => (string) ($row->user_email ?? ''),
                'userPhone' => (string) ($row->user_phone ?? ''),
                'planTier' => (string) $row->plan_tier,
                'planNameAr' => (string) $row->plan_name_ar,
                'planNameEn' => (string) ($row->plan_name_en ?: $row->plan_name_ar),
                'billingPeriod' => (string) $row->billing_period,
                'baseAmountEgp' => round((float) ($row->base_amount_egp ?? $row->amount_egp ?? 0), 2),
                'discountAmountEgp' => round((float) ($row->discount_amount_egp ?? 0), 2),
                'amountEgp' => round((float) ($row->amount_egp ?? 0), 2),
                'couponCode' => $row->coupon_code ?: null,
                'paymentMethod' => (string) ($row->payment_method ?? ''),
                'paymentMethodLabelAr' => $pmEnum?->label() ?? ((string) ($row->payment_method ?? '')),
                'transactionReference' => $row->transaction_reference ?: null,
                'senderReference' => $row->sender_reference ?: null,
                'hasReceipt' => ! empty($row->receipt_path),
                'receiptUrl' => $this->receiptService->signedUrl($rKey, (string) $row->uuid, $row->receipt_path),
                'receiptOriginalName' => $row->receipt_original_name ?: null,
                'receiptMimeType' => $row->receipt_mime_type ?: null,
                'isFreePromo' => (bool) ($row->is_free_promo ?? false),
                'status' => (string) $row->status,
                'rejectionReason' => $row->rejection_reason ?: null,
                'submittedAt' => $createdAt?->toIso8601String(),
                'submittedDate' => $createdAt?->format('Y-m-d H:i'),
                'reviewedAt' => $reviewedAt?->toIso8601String(),
                'reviewerName' => $row->reviewer_name ?: null,
            ];
        })->values()->all();

        $pendingCount = (int) StudentSubscription::where('status', 'pending_review')->count()
            + (int) TeacherSubscription::where('status', 'pending_review')->count()
            + (int) CenterSubscription::where('status', 'pending_review')->count();

        $pendingAmountEgp = round(
            (float) StudentSubscription::where('status', 'pending_review')->sum('amount_egp')
            + (float) TeacherSubscription::where('status', 'pending_review')->sum('amount_egp')
            + (float) CenterSubscription::where('status', 'pending_review')->sum('amount_egp'),
            2
        );

        $approvedCount = (int) StudentSubscription::where('status', 'active')->where('plan_tier', '!=', 'free')->count()
            + (int) TeacherSubscription::where('status', 'active')->where('plan_tier', '!=', 'free')->count()
            + (int) CenterSubscription::where('status', 'active')->where('plan_tier', '!=', 'free')->count();

        $rejectedCount = (int) StudentSubscription::where('status', 'rejected')->count()
            + (int) TeacherSubscription::where('status', 'rejected')->count()
            + (int) CenterSubscription::where('status', 'rejected')->count();

        return [
            'summary' => [
                'pendingCount' => $pendingCount,
                'pendingAmountEgp' => $pendingAmountEgp,
                'approvedCount' => $approvedCount,
                'rejectedCount' => $rejectedCount,
            ],
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * Atomically approve a pending paid subscription request across Student, Teacher, or Center roles.
     *
     * @return array<string, mixed>
     */
    public function approveSubscriptionRequest(User $admin, string $role, string $uuid): array
    {
        $role = strtolower(trim($role));
        if (! in_array($role, ['student', 'teacher', 'center'], true)) {
            throw ValidationException::withMessages(['role' => ['نوع الحساب غير صالح.']]);
        }

        $result = $this->transaction(function () use ($admin, $role, $uuid): array {
            $now = Carbon::now();

            if ($role === 'student') {
                /** @var StudentSubscription $sub */
                $sub = StudentSubscription::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                $this->assertSubscriptionPending($sub->status);

                $period = $sub->billing_period instanceof BillingPeriod
                    ? $sub->billing_period
                    : (BillingPeriod::tryFrom((string) $sub->billing_period) ?? BillingPeriod::MONTHLY);

                StudentSubscription::where('student_id', $sub->student_id)
                    ->where('id', '!=', $sub->id)
                    ->where('status', 'active')
                    ->update(['status' => 'superseded']);

                $sub->update([
                    'status' => 'active',
                    'starts_at' => $now,
                    'renews_at' => $now->copy()->addDays($period->durationDays()),
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => $now,
                    'rejection_reason' => null,
                ]);

                StudentInvoice::where('subscription_id', $sub->id)->update([
                    'status' => InvoiceStatus::PAID->value,
                    'paid_at' => $now,
                    'rejection_reason' => null,
                ]);

                $userId = (int) $sub->student_id;
            } elseif ($role === 'teacher') {
                /** @var TeacherSubscription $sub */
                $sub = TeacherSubscription::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                $this->assertSubscriptionPending($sub->status);

                $period = $sub->billing_period instanceof BillingPeriod
                    ? $sub->billing_period
                    : (BillingPeriod::tryFrom((string) $sub->billing_period) ?? BillingPeriod::MONTHLY);

                TeacherSubscription::where('teacher_id', $sub->teacher_id)
                    ->where('id', '!=', $sub->id)
                    ->where('status', 'active')
                    ->update(['status' => 'superseded']);

                $sub->update([
                    'status' => 'active',
                    'starts_at' => $now,
                    'renews_at' => $now->copy()->addDays($period->durationDays()),
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => $now,
                    'rejection_reason' => null,
                ]);

                $tierVal = $sub->plan_tier instanceof TeacherPlanTier ? $sub->plan_tier->value : (string) $sub->plan_tier;
                $teacherUser = User::find($sub->teacher_id);
                $teacherProfile = TeacherProfile::firstOrCreate(
                    ['user_id' => $sub->teacher_id],
                    ['name_ar' => $teacherUser?->name ?: 'المعلم']
                );
                $teacherProfile->forceFill(['plan_tier' => $tierVal])->save();

                TeacherInvoice::where('subscription_id', $sub->id)->update([
                    'status' => InvoiceStatus::PAID->value,
                    'paid_at' => $now,
                    'rejection_reason' => null,
                ]);

                $userId = (int) $sub->teacher_id;
            } else {
                /** @var CenterSubscription $sub */
                $sub = CenterSubscription::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                $this->assertSubscriptionPending($sub->status);

                $period = $sub->billing_period instanceof BillingPeriod
                    ? $sub->billing_period
                    : (BillingPeriod::tryFrom((string) $sub->billing_period) ?? BillingPeriod::MONTHLY);

                CenterSubscription::where('center_id', $sub->center_id)
                    ->where('id', '!=', $sub->id)
                    ->where('status', 'active')
                    ->update(['status' => 'superseded']);

                $sub->update([
                    'status' => 'active',
                    'starts_at' => $now,
                    'renews_at' => $now->copy()->addDays($period->durationDays()),
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => $now,
                    'rejection_reason' => null,
                ]);

                $tierVal = $sub->plan_tier instanceof CenterPlanTier ? $sub->plan_tier->value : (string) $sub->plan_tier;
                $centerUser = User::find($sub->center_id);
                $centerProfile = CenterProfile::firstOrCreate(
                    ['user_id' => $sub->center_id],
                    ['name_ar' => $centerUser?->name ?: 'السنتر التعليمي']
                );
                $centerProfile->forceFill(['plan_tier' => $tierVal])->save();

                CenterInvoice::where('subscription_id', $sub->id)->update([
                    'status' => InvoiceStatus::PAID->value,
                    'paid_at' => $now,
                    'rejection_reason' => null,
                ]);

                $userId = (int) $sub->center_id;
            }

            $this->logSecurityEvent('admin.subscription.approved', [
                'admin_id' => $admin->id,
                'role' => $role,
                'user_id' => $userId,
                'subscription_uuid' => $sub->uuid,
                'plan_tier' => is_object($sub->plan_tier) ? $sub->plan_tier->value : (string) $sub->plan_tier,
                'amount_egp' => (float) $sub->amount_egp,
                'transaction_reference' => $sub->transaction_reference,
            ], 'info', $sub);

            return [
                'id' => $sub->uuid,
                'role' => $role,
                'status' => 'active',
                'planTier' => is_object($sub->plan_tier) ? $sub->plan_tier->value : (string) $sub->plan_tier,
                'planNameAr' => $sub->plan_name_ar,
                'amountEgp' => (float) $sub->amount_egp,
                'startsAt' => $sub->starts_at?->toIso8601String(),
                'renewsAt' => $sub->renews_at?->toIso8601String(),
                'reviewedAt' => $sub->reviewed_at?->toIso8601String(),
            ];
        });

        $this->settingsService->bumpCacheEpoch();

        return $result;
    }

    /**
     * Atomically reject a pending paid subscription request with an explicit reason.
     *
     * @return array<string, mixed>
     */
    public function rejectSubscriptionRequest(User $admin, string $role, string $uuid, string $reason): array
    {
        $role = strtolower(trim($role));
        if (! in_array($role, ['student', 'teacher', 'center'], true)) {
            throw ValidationException::withMessages(['role' => ['نوع الحساب غير صالح.']]);
        }

        $cleanReason = mb_substr(trim(strip_tags($reason)), 0, 500);
        if (mb_strlen($cleanReason) < 3) {
            throw ValidationException::withMessages(['reason' => ['يرجى توضيح سبب رفض طلب الاشتراك (3 أحرف على الأقل).']]);
        }

        $result = $this->transaction(function () use ($admin, $role, $uuid, $cleanReason): array {
            $now = Carbon::now();

            if ($role === 'student') {
                /** @var StudentSubscription $sub */
                $sub = StudentSubscription::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                $this->assertSubscriptionPending($sub->status);

                $sub->update([
                    'status' => 'rejected',
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => $now,
                    'rejection_reason' => $cleanReason,
                ]);

                StudentInvoice::where('subscription_id', $sub->id)->update([
                    'status' => InvoiceStatus::FAILED->value,
                    'rejection_reason' => $cleanReason,
                ]);
                $userId = (int) $sub->student_id;
            } elseif ($role === 'teacher') {
                /** @var TeacherSubscription $sub */
                $sub = TeacherSubscription::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                $this->assertSubscriptionPending($sub->status);

                $sub->update([
                    'status' => 'rejected',
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => $now,
                    'rejection_reason' => $cleanReason,
                ]);

                TeacherInvoice::where('subscription_id', $sub->id)->update([
                    'status' => InvoiceStatus::FAILED->value,
                    'rejection_reason' => $cleanReason,
                ]);
                $userId = (int) $sub->teacher_id;
            } else {
                /** @var CenterSubscription $sub */
                $sub = CenterSubscription::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                $this->assertSubscriptionPending($sub->status);

                $sub->update([
                    'status' => 'rejected',
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => $now,
                    'rejection_reason' => $cleanReason,
                ]);

                CenterInvoice::where('subscription_id', $sub->id)->update([
                    'status' => InvoiceStatus::FAILED->value,
                    'rejection_reason' => $cleanReason,
                ]);
                $userId = (int) $sub->center_id;
            }

            $this->logSecurityEvent('admin.subscription.rejected', [
                'admin_id' => $admin->id,
                'role' => $role,
                'user_id' => $userId,
                'subscription_uuid' => $sub->uuid,
                'reason' => $cleanReason,
            ], 'warning', $sub);

            return [
                'id' => $sub->uuid,
                'role' => $role,
                'status' => 'rejected',
                'rejectionReason' => $cleanReason,
                'reviewedAt' => $sub->reviewed_at?->toIso8601String(),
            ];
        });

        $this->settingsService->bumpCacheEpoch();

        return $result;
    }

    protected function assertSubscriptionPending(string $status): void
    {
        if ($status !== 'pending_review') {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'تمت معالجة طلب الاشتراك هذا مسبقاً ولا يمكن تكرار الإجراء.',
                'error_code' => 'SUBSCRIPTION_ALREADY_PROCESSED',
            ], Response::HTTP_CONFLICT));
        }
    }

    /* =========================================================================
       TEACHER & CENTER PAYOUT REQUESTS (ATOMIC, DOUBLE-PAYOUT PROOF)
       ========================================================================= */

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listPayouts(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $recipientType = isset($filters['recipient_type']) ? strtolower(trim((string) $filters['recipient_type'])) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $query = PlatformPayoutRequest::query()->latest('requested_at')->latest('id');

        if ($status && in_array($status, PayoutRequestStatus::values(), true)) {
            $query->where('status', $status);
        }

        if ($recipientType && in_array($recipientType, ['teacher', 'center'], true)) {
            $query->where('recipient_type', $recipientType);
        }

        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $query->where(function (Builder $q) use ($safe): void {
                $q->where('recipient_name_ar', 'like', "%{$safe}%")
                    ->orWhere('recipient_name_en', 'like', "%{$safe}%")
                    ->orWhere('reference_number', 'like', "%{$safe}%");
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        return [
            'summary' => [
                'pendingCount' => (int) PlatformPayoutRequest::query()->where('status', PayoutRequestStatus::PENDING->value)->count(),
                'pendingTotalEgp' => round((float) PlatformPayoutRequest::query()->where('status', PayoutRequestStatus::PENDING->value)->sum('amount_egp'), 2),
                'completedTotalEgp' => round((float) PlatformPayoutRequest::query()->where('status', PayoutRequestStatus::COMPLETED->value)->sum('amount_egp'), 2),
            ],
            'items' => collect($paginator->items())
                ->map(fn (PlatformPayoutRequest $p) => $p->toFrontendArray())
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPayoutRequest(User $admin, array $data): array
    {
        $rawDestination = isset($data['destination']) ? trim((string) $data['destination']) : null;

        $payout = PlatformPayoutRequest::create([
            'recipient_user_id' => $data['recipient_user_id'] ?? null,
            'recipient_type' => $data['recipient_type'],
            'recipient_name_ar' => $data['recipient_name_ar'],
            'recipient_name_en' => $data['recipient_name_en'] ?? $data['recipient_name_ar'],
            'amount_egp' => round((float) $data['amount_egp'], 2),
            'payout_method' => $data['payout_method'],
            'method_label_ar' => $data['method_label_ar'] ?? null,
            'method_label_en' => $data['method_label_en'] ?? null,
            'payout_destination_encrypted' => $rawDestination ?: null,
            'payout_destination_masked' => PlatformPayoutRequest::maskDestination($rawDestination),
            'notes_ar' => $data['notes_ar'] ?? null,
            'notes_en' => $data['notes_en'] ?? ($data['notes_ar'] ?? null),
            'requested_at' => now(),
        ]);

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.payout.created', [
            'admin_id' => $admin->id,
            'payout_uuid' => $payout->uuid,
            'amount_egp' => $payout->amount_egp,
            'recipient_type' => $payout->recipient_type,
        ], 'info', $payout);

        return $payout->toFrontendArray();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function approvePayout(User $admin, string $uuid, array $data = []): array
    {
        /** @var PlatformPayoutRequest $payout */
        $payout = $this->transaction(function () use ($admin, $uuid, $data): PlatformPayoutRequest {
            /** @var PlatformPayoutRequest $locked */
            $locked = PlatformPayoutRequest::query()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PayoutRequestStatus::PENDING) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => __('messages.admin.payout_already_processed'),
                    'error_code' => 'PAYOUT_ALREADY_PROCESSED',
                ], Response::HTTP_CONFLICT));
            }

            $locked->forceFill([
                'status' => PayoutRequestStatus::COMPLETED,
                'transfer_reference' => $data['transfer_reference'] ?? ('TRX-'.strtoupper(substr(md5($locked->uuid.now()->timestamp), 0, 10))),
                'processed_at' => now(),
                'processed_by' => $admin->id,
            ])->save();

            if (! empty($data['notes'])) {
                $locked->update(['notes_ar' => $data['notes']]);
            }

            return $locked;
        });

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.payout.approved', [
            'admin_id' => $admin->id,
            'payout_uuid' => $payout->uuid,
            'reference_number' => $payout->reference_number,
            'amount_egp' => $payout->amount_egp,
            'transfer_reference' => $payout->transfer_reference,
        ], 'warning', $payout);

        return $payout->fresh()->toFrontendArray();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function rejectPayout(User $admin, string $uuid, array $data = []): array
    {
        /** @var PlatformPayoutRequest $payout */
        $payout = $this->transaction(function () use ($admin, $uuid, $data): PlatformPayoutRequest {
            /** @var PlatformPayoutRequest $locked */
            $locked = PlatformPayoutRequest::query()
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PayoutRequestStatus::PENDING) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => __('messages.admin.payout_already_processed'),
                    'error_code' => 'PAYOUT_ALREADY_PROCESSED',
                ], Response::HTTP_CONFLICT));
            }

            $locked->forceFill([
                'status' => PayoutRequestStatus::REJECTED,
                'rejection_reason' => $data['rejection_reason'] ?? __('messages.admin.payout_rejected_default_reason'),
                'processed_at' => now(),
                'processed_by' => $admin->id,
            ])->save();

            return $locked;
        });

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.payout.rejected', [
            'admin_id' => $admin->id,
            'payout_uuid' => $payout->uuid,
            'reason' => $payout->rejection_reason,
        ], 'warning', $payout);

        return $payout->fresh()->toFrontendArray();
    }

    /* =========================================================================
       DISCOUNT COUPONS MANAGEMENT (UNIFIED ACROSS ALL PORTALS)
       ========================================================================= */

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listCoupons(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 30), 1), 100);
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;

        $query = DiscountCoupon::query()->latest('id');

        if ($search !== null && $search !== '') {
            $safe = addcslashes(strtoupper($search), '%_\\');
            $query->where('code', 'like', "%{$safe}%");
        }

        if ($status === 'active') {
            $query->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        } elseif ($status === 'expired') {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        return [
            'items' => collect($paginator->items())
                ->map(fn (DiscountCoupon $cp) => $this->formatCoupon($cp))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createCoupon(User $admin, array $data): array
    {
        $coupon = DiscountCoupon::create([
            'code' => strtoupper(trim((string) $data['code'])),
            'discount_percent' => (int) $data['discount_percent'],
            'max_discount_egp' => isset($data['max_discount_egp']) ? (float) $data['max_discount_egp'] : null,
            'min_order_egp' => (float) ($data['min_order_egp'] ?? 0),
            'max_uses' => isset($data['max_uses']) ? (int) $data['max_uses'] : null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'used_count' => 0,
        ]);

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.coupon.created', [
            'admin_id' => $admin->id,
            'coupon_uuid' => $coupon->uuid,
            'code' => $coupon->code,
            'discount_percent' => $coupon->discount_percent,
        ], 'info', $coupon);

        return $this->formatCoupon($coupon);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateCoupon(User $admin, string $uuid, array $data): array
    {
        /** @var DiscountCoupon $coupon */
        $coupon = DiscountCoupon::query()->where('uuid', $uuid)->firstOrFail();

        $allowed = ['code', 'discount_percent', 'max_discount_egp', 'min_order_egp', 'max_uses', 'expires_at', 'is_active'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (isset($payload['code'])) {
            $payload['code'] = strtoupper(trim((string) $payload['code']));
        }

        $coupon->fill($payload)->save();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.coupon.updated', [
            'admin_id' => $admin->id,
            'coupon_uuid' => $coupon->uuid,
            'code' => $coupon->code,
        ], 'info', $coupon);

        return $this->formatCoupon($coupon->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleCouponActive(User $admin, string $uuid): array
    {
        /** @var DiscountCoupon $coupon */
        $coupon = DiscountCoupon::query()->where('uuid', $uuid)->firstOrFail();

        $coupon->update(['is_active' => ! (bool) $coupon->is_active]);
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.coupon.toggled', [
            'admin_id' => $admin->id,
            'coupon_uuid' => $coupon->uuid,
            'is_active' => (bool) $coupon->is_active,
        ], 'info', $coupon);

        return $this->formatCoupon($coupon->fresh());
    }

    public function deleteCoupon(User $admin, string $uuid): void
    {
        /** @var DiscountCoupon $coupon */
        $coupon = DiscountCoupon::query()->where('uuid', $uuid)->firstOrFail();

        $code = $coupon->code;
        $coupon->delete();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.coupon.deleted', [
            'admin_id' => $admin->id,
            'coupon_uuid' => $uuid,
            'code' => $code,
        ], 'warning');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatCoupon(DiscountCoupon $cp): array
    {
        $isExpired = $cp->expires_at !== null && $cp->expires_at->isPast();
        $isDepleted = $cp->max_uses !== null && $cp->used_count >= $cp->max_uses;

        $status = ! $cp->is_active
            ? 'inactive'
            : ($isExpired ? 'expired' : ($isDepleted ? 'depleted' : 'active'));

        return [
            'id' => $cp->uuid,
            'code' => $cp->code,
            'discountPercent' => (int) $cp->discount_percent,
            'maxDiscountEgp' => $cp->max_discount_egp !== null ? (float) $cp->max_discount_egp : null,
            'minOrderEgp' => (float) $cp->min_order_egp,
            'usageCount' => (int) $cp->used_count,
            'maxUses' => $cp->max_uses !== null ? (int) $cp->max_uses : null,
            'expiryDate' => $cp->expires_at?->toDateString(),
            'expiresAt' => $cp->expires_at?->toIso8601String(),
            'isActive' => (bool) $cp->is_active,
            'status' => $status,
            'createdAt' => $cp->created_at?->toIso8601String(),
        ];
    }

    /**
     * Authoritative 9-plan definitions matching CenterBillingService, TeacherBillingService, and StudentBillingService
     * with complete Arabic & English features and exact Monthly / Term (5-mo) / Annual prices.
     *
     * @return list<array<string, mixed>>
     */
    public static function canonicalDefaultPlanDefinitions(): array
    {
        return [
            // ── 1. Centers (السناتر التعليمية) ──
            [
                'slug' => 'plan-center-free',
                'target_role' => 'center',
                'tier_key' => 'free',
                'name_ar' => 'باقة السنتر المجانية',
                'name_en' => 'Center Free',
                'billing_cycle' => 'monthly',
                'price_egp' => 0,
                'term_price_egp' => 0,
                'annual_price_egp' => 0,
                'badge_ar' => 'باقة تجريبية مجانية',
                'badge_en' => 'Starter Free',
                'is_popular' => false,
                'display_order' => 1,
                'features_ar' => [
                    'سعة استيعاب حتى 20 مستخدم نشط فقط (طلاب ومعلمون)',
                    'فرع واحد رئيسي وقاعة دراسية واحدة فقط',
                    'إدارة حتى معلمين (2) ومجموعتين دراسيتين (2)',
                    'تسجيل الحضور اليدوي الأساسي وإدارة سجل الطلاب',
                    'لوحة تحكم أساسية لمتابعة المجموعات',
                ],
                'features_en' => [
                    'Up to 20 active users max (students & teachers)',
                    '1 main branch and 1 classroom only',
                    'Manage up to 2 teachers and 2 study cohorts',
                    'Basic manual student attendance and roster tracking',
                    'Essential center overview dashboard',
                ],
            ],
            [
                'slug' => 'plan-center-plus',
                'target_role' => 'center',
                'tier_key' => 'plus',
                'name_ar' => 'باقة السنتر بلس (Plus)',
                'name_en' => 'Center Plus',
                'billing_cycle' => 'monthly',
                'price_egp' => 200,
                'term_price_egp' => 800,
                'annual_price_egp' => 1500,
                'badge_ar' => 'الأنسب للسناتر النامية',
                'badge_en' => 'Best for Growing Centers',
                'is_popular' => false,
                'display_order' => 2,
                'features_ar' => [
                    'سعة حتى 500 مستخدم نشط مسجل بالسنتر',
                    'حتى 5 فروع، 8 قاعات دراسية، 12 معلماً، و 20 مجموعة',
                    'إدارة حتى 15 موظفاً إدارياً بصلاحيات مخصصة',
                    'ماسح الباركود و QR الفوري (USB + كاميرا) وبطاقات الطلاب',
                    'الجدول الأسبوعي الشامل وكشف التعارض الزمني الذكي',
                    'نظام الماليات وتسويات نسب الأرباح والمصروفات',
                ],
                'features_en' => [
                    'Up to 500 active center users capacity',
                    'Up to 5 branches, 8 classrooms, 12 teachers & 20 cohorts',
                    'Manage up to 15 administrative staff with custom roles',
                    'High-speed QR / Barcode scanner (USB & Camera) & ID cards',
                    'Full weekly master schedule with smart clash detection',
                    'Financials ERP, commission splits & expense tracking',
                ],
            ],
            [
                'slug' => 'plan-center-pro',
                'target_role' => 'center',
                'tier_key' => 'pro',
                'name_ar' => 'باقة السنتر برو (Pro)',
                'name_en' => 'Center Pro',
                'billing_cycle' => 'monthly',
                'price_egp' => 400,
                'term_price_egp' => 1600,
                'annual_price_egp' => 3000,
                'badge_ar' => 'الأكثر طلباً للسناتر الكبرى',
                'badge_en' => 'Most Popular',
                'is_popular' => true,
                'display_order' => 3,
                'features_ar' => [
                    'جميع مزايا باقة السنتر بلس بالكامل بدون أي حدود',
                    'عدد غير محدود من المستخدمين، الطلاب، المعلمين، والموظفين',
                    'عدد غير محدود من الفروع، القاعات الدراسية، والمجموعات',
                    'سجل العمليات والرقابة والأمان الكامل (Audit Log)',
                    'التقرير التنفيذي الشامل لمقارنة أداء الفروع والقاعات',
                    'تنبيهات واتساب، الهوية المخصصة، والنسخ الاحتياطي السحابي',
                ],
                'features_en' => [
                    'Everything in Center Plus with zero limits',
                    'Unlimited users, students, teachers & staff',
                    'Unlimited branches, classrooms & study cohorts',
                    'Immutable Security Audit Logs',
                    'Executive Performance Report across all branches',
                    'WhatsApp parent alerts, White-Label & Cloud Backups',
                ],
            ],

            // ── 2. Teachers (المعلمون) ──
            [
                'slug' => 'plan-teacher-free',
                'target_role' => 'teacher',
                'tier_key' => 'free',
                'name_ar' => 'الباقة المجانية للمعلم',
                'name_en' => 'Teacher Free',
                'billing_cycle' => 'monthly',
                'price_egp' => 0,
                'term_price_egp' => 0,
                'annual_price_egp' => 0,
                'commission_percent' => 15,
                'badge_ar' => 'مجانية 100%',
                'badge_en' => '100% Free',
                'is_popular' => false,
                'display_order' => 1,
                'features_ar' => [
                    'إنشاء كورس دراسي واحد (1 كورس فقط)',
                    'رفع حتى 3 حصص دراسية (صوت أو فيديو أو PDF) بدون AI',
                    'إنشاء مجموعة دراسية واحدة فقط بحد أقصى 50 طالب',
                    'إدارة قائمة طلاب المجموعة وتسجيل الحضور والغياب بالباركود',
                ],
                'features_en' => [
                    'Create 1 active course',
                    'Upload up to 3 lessons (Audio, Video, or PDF) without AI',
                    'Create 1 study group with up to 50 students max',
                    'Student roster management and QR attendance tracking',
                ],
            ],
            [
                'slug' => 'plan-teacher-plus',
                'target_role' => 'teacher',
                'tier_key' => 'plus',
                'name_ar' => 'باقة المعلم بلس (Plus)',
                'name_en' => 'Teacher Plus',
                'billing_cycle' => 'monthly',
                'price_egp' => 120,
                'term_price_egp' => 480,
                'annual_price_egp' => 900,
                'commission_percent' => 10,
                'badge_ar' => 'الأكثر اختياراً للمعلمين',
                'badge_en' => 'Most Popular',
                'is_popular' => true,
                'display_order' => 2,
                'features_ar' => [
                    'إنشاء حتى 12 مجموعة دراسية بعدد طلاب مفتوح في كل مجموعة',
                    'إنشاء عدد غير محدود من الكورسات ورفع عدد غير محدود من الحصص',
                    'الجدول الأسبوعي الكامل لتنظيم مواعيد المجموعات والملاحظات بدون تعارض',
                    'الامتحانات الإلكترونية وبنك الأسئلة + الواجبات والتصحيح الكامل',
                    'دوري الكورس التنافسي، الشهادات المعتمدة بكود QR، وإدارة المدفوعات والماليات',
                    'العلامة المائية الديناميكية لحماية الحصص وتنبيهات ولي الأمر',
                ],
                'features_en' => [
                    'Create up to 12 study groups with unlimited students',
                    'Unlimited courses and unlimited lesson uploads',
                    'Full Weekly Schedule organizer with conflict detection & notes',
                    'Online Exams, Question Bank & Homework grading workflows',
                    'Course Leagues, QR-verified Certificates & Direct Financials',
                    'Dynamic DRM watermark protection & parent alerts',
                ],
            ],
            [
                'slug' => 'plan-teacher-pro',
                'target_role' => 'teacher',
                'tier_key' => 'pro',
                'name_ar' => 'باقة المعلم برو (Pro)',
                'name_en' => 'Teacher Pro',
                'billing_cycle' => 'monthly',
                'price_egp' => 150,
                'term_price_egp' => 600,
                'annual_price_egp' => 1125,
                'commission_percent' => 5,
                'badge_ar' => 'شاملة AI ومجموعات مفتوحة',
                'badge_en' => 'All AI • Unlimited Groups',
                'is_popular' => false,
                'display_order' => 3,
                'features_ar' => [
                    'كافة مزايا باقة المعلم بلس بدون أي قيود',
                    'إنشاء عدد غير محدود من المجموعات الدراسية (أكثر من 12 مجموعة)',
                    'التفريغ النصي الذكي المتزامن للحصص بالذكاء الاصطناعي (AI Speech-to-Text)',
                    'توليد خرائط المفاهيم التفاعلية (NotebookLM Concept Graph) تلقائياً لكل حصة',
                    'فهرسة الفصول والمفاهيم الذكية وشاشة معالجة الحصص بالذكاء الاصطناعي',
                ],
                'features_en' => [
                    'Everything in Teacher Plus with zero limits',
                    'Unlimited study groups (beyond 12 groups)',
                    'AI Synchronized Lesson Transcript (Speech-to-Text)',
                    'AI Interactive Concept Mind Maps (NotebookLM Concept Graph)',
                    'AI Smart Chapter Indexing & AI Lesson Processing Studio',
                ],
            ],

            // ── 3. Students (الطلاب) ──
            [
                'slug' => 'plan-student-free',
                'target_role' => 'student',
                'tier_key' => 'free',
                'name_ar' => 'باقة الطالب المجانية',
                'name_en' => 'Student Free',
                'billing_cycle' => 'monthly',
                'price_egp' => 0,
                'term_price_egp' => 0,
                'annual_price_egp' => 0,
                'badge_ar' => 'مجانية 100%',
                'badge_en' => '100% Free',
                'is_popular' => false,
                'display_order' => 1,
                'features_ar' => [
                    'الوصول للحصص المنشورة في مجموعاتك والملخصات الأساسية',
                    'الانضمام لمجموعات المعلمين عبر كود الدعوة وحل الواجبات',
                    'متابعة الجدول الأسبوعي وسجل الحضور والاستريك اليومي',
                    'حل الاختبارات الدورية المقررة من المعلم',
                ],
                'features_en' => [
                    'Access published lessons and core notes in your groups',
                    'Join teacher study groups via join code and submit homework',
                    'Weekly study schedule, attendance log, and daily streak tracker',
                    'Take scheduled exams assigned by your teachers',
                ],
            ],
            [
                'slug' => 'plan-student-plus',
                'target_role' => 'student',
                'tier_key' => 'plus',
                'name_ar' => 'باقة الطالب بلس (Plus)',
                'name_en' => 'Student Plus',
                'billing_cycle' => 'monthly',
                'price_egp' => 50,
                'term_price_egp' => 200,
                'annual_price_egp' => 350,
                'badge_ar' => 'شاملة الدوريات والتفوق',
                'badge_en' => 'Leagues & Honors',
                'is_popular' => false,
                'display_order' => 2,
                'features_ar' => [
                    'كل مزايا الباقة المجانية بدون قيود أو إعلانات',
                    'المنافسة الكاملة في دوري المعلمين ودوري أبطال الجمهورية ونظام النقاط والترتيب',
                    'لوحة إنجازاتي الكاملة: التحديات الأسبوعية، شارات التميز، وخريطة الالتزام اليومي',
                    'شهادات إتمام وتفوق رسمية موثّقة برقم تسلسلي وكود QR',
                    'اختبارات تدريبية وبنوك أسئلة غير محدودة مع تحليلات الأداء وتقارير ولي الأمر',
                ],
                'features_en' => [
                    'Everything in Student Free with zero limits',
                    'Full access to Teacher Course Leagues, Republic League & official rankings',
                    'Achievements hub: weekly challenges, badges & 52-week activity heatmap',
                    'Official verified certificates with serial ID and QR verification',
                    'Unlimited practice exams, performance analytics & weekly parent reports',
                ],
            ],
            [
                'slug' => 'plan-student-pro',
                'target_role' => 'student',
                'tier_key' => 'pro',
                'name_ar' => 'باقة الطالب برو (Pro)',
                'name_en' => 'Student Pro',
                'billing_cycle' => 'monthly',
                'price_egp' => 80,
                'term_price_egp' => 320,
                'annual_price_egp' => 560,
                'badge_ar' => 'الأكثر طلباً • شاملة AI',
                'badge_en' => 'Most Popular • AI',
                'is_popular' => true,
                'display_order' => 3,
                'features_ar' => [
                    'كل مزايا باقة الطالب بلس (الدوريات والأوسمة والشهادات) بلا حدود',
                    'تحويل المحاضرات الذكي بالـ AI (تفريغ صوت وفيديو وPDF إلى ملخصات)',
                    'توليد كويزات البابل شيت الفورية من أي محاضرة مع شرح كل إجابة',
                    'مركز تقوية نقاط الضعف الذكي وخرائط المفاهيم وخطة المراجعة الشخصية',
                    'أولوية كاملة في معسكرات المراجعة النهائية ودعم فني سريع',
                ],
                'features_en' => [
                    'Everything in Student Plus (Leagues, Badges & Certificates) unlimited',
                    'AI Smart Lecture Converter (Audio, Video & PDF to smart summaries)',
                    'Instant Bubble-Sheet quiz generator with step-by-step explanations',
                    'AI Weak-Areas Hub, concept maps & personalized revision planner',
                    'Priority access to final revision bootcamps & fast support',
                ],
            ],
        ];
    }

    protected function seedDefaultPlans(?int $adminId = null): void
    {
        foreach (self::canonicalDefaultPlanDefinitions() as $row) {
            $plan = new PlatformSubscriptionPlan($row);
            $plan->forceFill(['updated_by' => $adminId])->save();
        }
    }

    /**
     * Ensure any untouched auto-seeded rows in `platform_subscription_plans` have the complete
     * canonical feature lists & term/annual prices from the billing services (without overwriting admin customizations).
     */
    protected function upgradeUnmodifiedSeededPlanFeaturesOnce(): void
    {
        $canonicalBySlug = [];
        foreach (self::canonicalDefaultPlanDefinitions() as $def) {
            $canonicalBySlug[$def['slug']] = $def;
        }

        $untouchedPlans = PlatformSubscriptionPlan::query()
            ->whereIn('slug', array_keys($canonicalBySlug))
            ->get();

        $legacyFirstLines = [
            'حتى 20 مستخدم نشط',
            'حتى 500 مستخدم نشط',
            'مستخدمون وفروع وقاعات غير محدودة',
            'إنشاء كورس واحد فقط',
            'حتى 12 مجموعة دراسية بطلاب مفتوحين',
            'كل مزايا بلس + مجموعات غير محدودة',
            'الحصص المنشورة في مجموعاتك',
            'دوري المعلمين ودوري أبطال الجمهورية',
            'كل مزايا طالب بلس بلا حدود',
        ];

        foreach ($untouchedPlans as $plan) {
            $def = $canonicalBySlug[$plan->slug] ?? null;
            if (! $def) {
                continue;
            }
            $firstFeat = is_array($plan->features_ar) ? ($plan->features_ar[0] ?? '') : '';
            if (in_array($firstFeat, $legacyFirstLines, true)) {
                $plan->update([
                    'name_ar' => $def['name_ar'],
                    'name_en' => $def['name_en'],
                    'price_egp' => $def['price_egp'],
                    'term_price_egp' => $def['term_price_egp'],
                    'annual_price_egp' => $def['annual_price_egp'],
                    'badge_ar' => $def['badge_ar'],
                    'badge_en' => $def['badge_en'],
                    'features_ar' => $def['features_ar'],
                    'features_en' => $def['features_en'],
                ]);
            }
        }
    }
}
