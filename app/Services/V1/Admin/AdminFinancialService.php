<?php

namespace App\Services\V1\Admin;

use App\Enums\DirectPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PayoutRequestStatus;
use App\Models\CenterInvoice;
use App\Models\DiscountCoupon;
use App\Models\PlatformPayoutRequest;
use App\Models\PlatformSubscriptionPlan;
use App\Models\StudentDirectPayment;
use App\Models\StudentInvoice;
use App\Models\TeacherInvoice;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AdminFinancialService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService,
        protected AdminDashboardService $dashboardService
    ) {}

    /**
     * Global Financial KPIs Overview: GMV, Subscription Revenue, Platform Commission, Settled Payouts & AI Costs.
     *
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        $cacheKey = $this->settingsService->cacheKey('admin:financials:overview');

        return Cache::remember($cacheKey, now()->addSeconds(60), function (): array {
            $commissionRate = ((float) $this->settingsService->get('platform_commission_percent', 20.0)) / 100.0;

            $teacherSubRev = (float) TeacherInvoice::query()
                ->where('status', InvoiceStatus::PAID->value)
                ->sum('final_amount_egp');
            $studentSubRev = (float) StudentInvoice::query()
                ->where('status', InvoiceStatus::PAID->value)
                ->sum('final_amount_egp');
            $centerSubRev = (float) CenterInvoice::query()
                ->where('status', InvoiceStatus::PAID->value)
                ->sum('final_amount_egp');

            $subscriptionRevenue = round($teacherSubRev + $studentSubRev + $centerSubRev, 2);

            $directPaymentsVolume = round((float) StudentDirectPayment::query()
                ->where('status', DirectPaymentStatus::CONFIRMED->value)
                ->sum('amount_egp'), 2);

            $gmvEgp = round($subscriptionRevenue + $directPaymentsVolume, 2);
            $platformCommissionEgp = round($subscriptionRevenue + ($directPaymentsVolume * $commissionRate), 2);

            $settledPayoutsEgp = round((float) PlatformPayoutRequest::query()
                ->where('status', PayoutRequestStatus::COMPLETED->value)
                ->sum('amount_egp'), 2);

            $pendingPayoutsEgp = round((float) PlatformPayoutRequest::query()
                ->where('status', PayoutRequestStatus::PENDING->value)
                ->sum('amount_egp'), 2);

            $pendingPayoutsCount = (int) PlatformPayoutRequest::query()
                ->where('status', PayoutRequestStatus::PENDING->value)
                ->count();

            $aiEcon = $this->dashboardService->computeAiEconomics();

            $activeCouponsCount = (int) DiscountCoupon::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count();

            return [
                'gmvEgp' => $gmvEgp,
                'subscriptionRevenueEgp' => $subscriptionRevenue,
                'subscriptionBreakdown' => [
                    'studentsEgp' => round($studentSubRev, 2),
                    'teachersEgp' => round($teacherSubRev, 2),
                    'centersEgp' => round($centerSubRev, 2),
                ],
                'directPaymentsVolumeEgp' => $directPaymentsVolume,
                'platformCommissionPercent' => round($commissionRate * 100, 2),
                'platformCommissionEgp' => $platformCommissionEgp,
                'settledPayoutsEgp' => $settledPayoutsEgp,
                'pendingPayoutsEgp' => $pendingPayoutsEgp,
                'pendingPayoutsCount' => $pendingPayoutsCount,
                'aiInfrastructureCostEgp' => $aiEcon['aiProcessingCostEgp'],
                'aiCostPerMinuteEgp' => $aiEcon['costPerMinuteEgp'],
                'activeCouponsCount' => $activeCouponsCount,
            ];
        });
    }

    /* =========================================================================
       SUBSCRIPTION PLANS & PRICING MANAGEMENT
       ========================================================================= */

    /**
     * List platform subscription plans (seeds authoritative defaults on first read if table is empty).
     *
     * @return list<array<string, mixed>>
     */
    public function listPlans(?string $targetRole = null, bool $onlyActive = false): array
    {
        if (PlatformSubscriptionPlan::query()->withTrashed()->count() === 0) {
            $this->seedDefaultPlans();
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

        return $query->get()
            ->map(fn (PlatformSubscriptionPlan $p) => $p->toFrontendArray())
            ->values()
            ->all();
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPlan(User $admin, array $data): array
    {
        $plan = new PlatformSubscriptionPlan([
            'slug' => $data['slug'] ?? null,
            'target_role' => $data['target_role'],
            'tier_key' => $data['tier_key'] ?? 'custom',
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? $data['name_ar'],
            'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
            'price_egp' => (float) $data['price_egp'],
            'term_price_egp' => isset($data['term_price_egp']) ? (float) $data['term_price_egp'] : null,
            'annual_price_egp' => (float) ($data['annual_price_egp'] ?? 0),
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
        ]);

        $plan->forceFill(['updated_by' => $admin->id])->save();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.plan.created', [
            'admin_id' => $admin->id,
            'plan_uuid' => $plan->uuid,
            'target_role' => $plan->target_role,
            'price_egp' => $plan->price_egp,
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
            'price_egp', 'term_price_egp', 'annual_price_egp', 'commission_percent',
            'badge_ar', 'badge_en', 'is_popular', 'is_active',
            'features_ar', 'features_en', 'display_order',
        ];

        $plan->fill(array_intersect_key($data, array_flip($allowed)));
        $plan->forceFill(['updated_by' => $admin->id])->save();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.plan.updated', [
            'admin_id' => $admin->id,
            'plan_uuid' => $plan->uuid,
            'price_before' => $beforePrice,
            'price_after' => (float) $plan->price_egp,
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
     * Create a payout settlement record (destination encrypted at rest + masked for display).
     *
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
     * Atomically approve & settle a pending payout request with row-level lock (prevents double payout).
     *
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
     * Atomically reject a pending payout request with row-level lock.
     *
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

    protected function seedDefaultPlans(?int $adminId = null): void
    {
        $defaults = [
            // Centers
            [
                'slug' => 'plan-center-free', 'target_role' => 'center', 'tier_key' => 'free',
                'name_ar' => 'باقة السنتر المجانية', 'name_en' => 'Center Free',
                'billing_cycle' => 'monthly', 'price_egp' => 0, 'term_price_egp' => 0, 'annual_price_egp' => 0,
                'badge_ar' => 'تجريبية مجانية', 'badge_en' => 'Free Start', 'is_popular' => false, 'display_order' => 1,
                'features_ar' => ['حتى 20 مستخدم نشط', 'فرع واحد وقاعة واحدة', 'إدارة حتى معلمين ومجموعتين', 'الحضور اليدوي وسجل الطلاب'],
                'features_en' => ['Up to 20 active users', '1 main branch & 1 classroom', 'Up to 2 teachers & 2 cohorts', 'Manual attendance & student roster'],
            ],
            [
                'slug' => 'plan-center-plus', 'target_role' => 'center', 'tier_key' => 'plus',
                'name_ar' => 'باقة السنتر بلس', 'name_en' => 'Center Plus',
                'billing_cycle' => 'monthly', 'price_egp' => 200, 'term_price_egp' => 800, 'annual_price_egp' => 1500,
                'badge_ar' => 'الأنسب للسناتر النامية', 'badge_en' => 'Growing Centers', 'is_popular' => false, 'display_order' => 2,
                'features_ar' => ['حتى 500 مستخدم نشط', 'حتى 5 فروع و 8 قاعات', 'ماسح الباركود و QR الفوري', 'الجدول الشامل ونظام الماليات'],
                'features_en' => ['Up to 500 active users', 'Up to 5 branches & 8 rooms', 'High-speed QR/Barcode scanner', 'Master schedule & Financials ERP'],
            ],
            [
                'slug' => 'plan-center-pro', 'target_role' => 'center', 'tier_key' => 'pro',
                'name_ar' => 'باقة السنتر برو', 'name_en' => 'Center Pro',
                'billing_cycle' => 'monthly', 'price_egp' => 400, 'term_price_egp' => 1600, 'annual_price_egp' => 3000,
                'badge_ar' => 'الأكثر طلباً للسناتر', 'badge_en' => 'Most Popular', 'is_popular' => true, 'display_order' => 3,
                'features_ar' => ['مستخدمون وفروع وقاعات غير محدودة', 'سجل الرقابة والأمان (Audit Log)', 'التقرير التنفيذي والنسخ السحابي', 'إشعارات واتساب والهوية الخاصة'],
                'features_en' => ['Unlimited users, branches & rooms', 'Immutable Security Audit Logs', 'Executive Reports & Cloud Backups', 'WhatsApp alerts & White-Label'],
            ],
            // Teachers
            [
                'slug' => 'plan-teacher-free', 'target_role' => 'teacher', 'tier_key' => 'free',
                'name_ar' => 'الباقة المجانية للمعلم', 'name_en' => 'Teacher Free',
                'billing_cycle' => 'monthly', 'price_egp' => 0, 'term_price_egp' => 0, 'annual_price_egp' => 0, 'commission_percent' => 15,
                'badge_ar' => 'مجانية 100%', 'badge_en' => 'Free Start', 'is_popular' => false, 'display_order' => 1,
                'features_ar' => ['إنشاء كورس واحد فقط', 'رفع حتى 3 حصص بدون AI', 'مجموعة دراسية واحدة (حتى 50 طالب)', 'إدارة الطلاب والحضور بالباركود'],
                'features_en' => ['1 active course', 'Up to 3 lessons without AI', '1 study group (up to 50 students)', 'Roster & QR attendance'],
            ],
            [
                'slug' => 'plan-teacher-plus', 'target_role' => 'teacher', 'tier_key' => 'plus',
                'name_ar' => 'باقة المعلم بلس (Plus)', 'name_en' => 'Teacher Plus',
                'billing_cycle' => 'monthly', 'price_egp' => 120, 'term_price_egp' => 480, 'annual_price_egp' => 900, 'commission_percent' => 10,
                'badge_ar' => 'الأكثر اختياراً للمعلمين', 'badge_en' => 'Most Popular', 'is_popular' => true, 'display_order' => 2,
                'features_ar' => ['حتى 12 مجموعة دراسية بطلاب مفتوحين', 'كورسات وحصص غير محدودة', 'الامتحانات والواجبات والدوري والشهادات', 'الجدول الأسبوعي والماليات'],
                'features_en' => ['Up to 12 study groups (unlimited students)', 'Unlimited courses & lessons', 'Exams, Homework, Leagues & Certificates', 'Weekly schedule & Financials'],
            ],
            [
                'slug' => 'plan-teacher-pro', 'target_role' => 'teacher', 'tier_key' => 'pro',
                'name_ar' => 'باقة المعلم برو (Pro)', 'name_en' => 'Teacher Pro',
                'billing_cycle' => 'monthly', 'price_egp' => 150, 'term_price_egp' => 600, 'annual_price_egp' => 1125, 'commission_percent' => 5,
                'badge_ar' => 'شاملة AI بالكامل', 'badge_en' => 'Full AI Included', 'is_popular' => false, 'display_order' => 3,
                'features_ar' => ['كل مزايا بلس + مجموعات غير محدودة', 'التفريغ النصي الذكي للحصص بالـ AI', 'خرائط المفاهيم التفاعلية بالـ AI', 'فهرسة الفصول الذكية'],
                'features_en' => ['Everything in Plus + unlimited groups', 'AI Synchronized Transcripts', 'AI Concept Mind Maps', 'Smart Chapter Indexing'],
            ],
            // Students
            [
                'slug' => 'plan-student-free', 'target_role' => 'student', 'tier_key' => 'free',
                'name_ar' => 'باقة الطالب المجانية', 'name_en' => 'Student Free',
                'billing_cycle' => 'monthly', 'price_egp' => 0, 'term_price_egp' => 0, 'annual_price_egp' => 0,
                'badge_ar' => 'مجانية', 'badge_en' => 'Free', 'is_popular' => false, 'display_order' => 1,
                'features_ar' => ['الحصص المنشورة في مجموعاتك', 'تسليم الواجبات والامتحانات الدورية', 'الجدول الأسبوعي والاستريك اليومي'],
                'features_en' => ['Published lessons in your groups', 'Homework & scheduled exams', 'Weekly schedule & daily streak'],
            ],
            [
                'slug' => 'plan-student-plus', 'target_role' => 'student', 'tier_key' => 'plus',
                'name_ar' => 'باقة الطالب بلس (Plus)', 'name_en' => 'Student Plus',
                'billing_cycle' => 'monthly', 'price_egp' => 50, 'term_price_egp' => 200, 'annual_price_egp' => 350,
                'badge_ar' => 'الدوريات والشهادات', 'badge_en' => 'Leagues & Honors', 'is_popular' => false, 'display_order' => 2,
                'features_ar' => ['دوري المعلمين ودوري أبطال الجمهورية', 'الشهادات المعتمدة بكود QR', 'بنوك الأسئلة والتحليلات وتقارير ولي الأمر'],
                'features_en' => ['Course & Republic Honors Leagues', 'Official QR Certificates', 'Practice exams, analytics & parent reports'],
            ],
            [
                'slug' => 'plan-student-pro', 'target_role' => 'student', 'tier_key' => 'pro',
                'name_ar' => 'باقة الطالب برو (Pro)', 'name_en' => 'Student Pro',
                'billing_cycle' => 'monthly', 'price_egp' => 80, 'term_price_egp' => 320, 'annual_price_egp' => 560,
                'badge_ar' => 'الأكثر اختياراً للطلبة', 'badge_en' => 'Best Value + AI', 'is_popular' => true, 'display_order' => 3,
                'features_ar' => ['كل مزايا طالب بلس بلا حدود', 'تحويل المحاضرات الذكي بالـ AI', 'توليد كويزات البابل شيت الفورية', 'مركز تقوية نقاط الضعف وخرائط المفاهيم'],
                'features_en' => ['Everything in Student Plus unlimited', 'AI Smart Lecture Converter', 'Instant Bubble-Sheet Quizzes', 'AI Weak-Areas Hub & Mind Maps'],
            ],
        ];

        foreach ($defaults as $row) {
            $plan = new PlatformSubscriptionPlan($row);
            $plan->forceFill(['updated_by' => $adminId])->save();
        }
    }
}
