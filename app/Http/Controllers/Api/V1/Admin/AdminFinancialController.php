<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Admin\ProcessAdminPayoutRequest;
use App\Http\Requests\V1\Admin\SaveAdminCouponRequest;
use App\Http\Requests\V1\Admin\SaveAdminSubscriptionPlanRequest;
use App\Http\Requests\V1\Admin\StoreAdminPayoutRequest;
use App\Services\V1\Admin\AdminFinancialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFinancialController extends BaseApiController
{
    public function __construct(
        protected AdminFinancialService $financialService
    ) {}

    /**
     * GET /api/v1/admin/financials/overview
     */
    public function overview(): JsonResponse
    {
        return $this->successResponse(
            $this->financialService->getOverview(),
            __('messages.admin.financials_fetched')
        );
    }

    /* ── Manual Platform Expenses & Costs Ledger ── */

    /**
     * GET /api/v1/admin/financials/expenses
     */
    public function expenses(Request $request): JsonResponse
    {
        $result = $this->financialService->listExpenses([
            'category' => $request->query('category'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', 50),
        ]);

        return $this->successResponse(
            $result['items'],
            'تم جلب سجل التكاليف والمصروفات بنجاح',
            200,
            array_merge($result['meta'], ['summary' => $result['summary']])
        );
    }

    /**
     * POST /api/v1/admin/financials/expenses
     */
    public function storeExpense(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:40'],
            'amountEgp' => ['nullable', 'numeric', 'min:0.01', 'max:50000000'],
            'amount_egp' => ['nullable', 'numeric', 'min:0.01', 'max:50000000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'expenseDate' => ['nullable', 'date'],
            'expense_date' => ['nullable', 'date'],
        ]);

        $expense = $this->financialService->createExpense($this->authenticatedUser(), $validated);

        return $this->createdResponse($expense, 'تم تسجيل بند التكلفة وتحديث صافي الربح بنجاح');
    }

    /**
     * PUT /api/v1/admin/financials/expenses/{uuid}
     */
    public function updateExpense(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:40'],
            'amountEgp' => ['nullable', 'numeric', 'min:0.01', 'max:50000000'],
            'amount_egp' => ['nullable', 'numeric', 'min:0.01', 'max:50000000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'expenseDate' => ['nullable', 'date'],
            'expense_date' => ['nullable', 'date'],
        ]);

        $expense = $this->financialService->updateExpense($this->authenticatedUser(), $uuid, $validated);

        return $this->successResponse($expense, 'تم تحديث بند التكلفة وصافي الربح بنجاح');
    }

    /**
     * DELETE /api/v1/admin/financials/expenses/{uuid}
     */
    public function destroyExpense(string $uuid): JsonResponse
    {
        $this->financialService->deleteExpense($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, 'تم حذف بند التكلفة وتحديث صافي الربح بنجاح');
    }

    /* ── Subscription Plans ── */

    /**
     * GET /api/v1/admin/financials/plans
     */
    public function plans(Request $request): JsonResponse
    {
        $role = $request->query('role') ? strtolower(trim((string) $request->query('role'))) : null;

        return $this->successResponse(
            $this->financialService->listPlans($role),
            __('messages.admin.plans_fetched')
        );
    }

    /**
     * POST /api/v1/admin/financials/plans
     */
    public function storePlan(SaveAdminSubscriptionPlanRequest $request): JsonResponse
    {
        $plan = $this->financialService->createPlan($this->authenticatedUser(), $request->validated());

        return $this->createdResponse($plan, __('messages.admin.plan_created'));
    }

    /**
     * PUT /api/v1/admin/financials/plans/{uuid}
     */
    public function updatePlan(SaveAdminSubscriptionPlanRequest $request, string $uuid): JsonResponse
    {
        $plan = $this->financialService->updatePlan($this->authenticatedUser(), $uuid, $request->validated());

        return $this->successResponse($plan, __('messages.admin.plan_updated'));
    }

    /**
     * PATCH /api/v1/admin/financials/plans/{uuid}/toggle-active
     */
    public function togglePlan(string $uuid): JsonResponse
    {
        $plan = $this->financialService->togglePlanActive($this->authenticatedUser(), $uuid);

        return $this->successResponse($plan, __('messages.admin.plan_toggled'));
    }

    /**
     * DELETE /api/v1/admin/financials/plans/{uuid}
     */
    public function destroyPlan(string $uuid): JsonResponse
    {
        $this->financialService->deletePlan($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.admin.plan_deleted'));
    }

    /**
     * POST /api/v1/admin/financials/plans/reset-defaults
     */
    public function resetPlans(): JsonResponse
    {
        $plans = $this->financialService->resetPlansToDefault($this->authenticatedUser());

        return $this->successResponse($plans, __('messages.admin.plans_reset'));
    }

    /* ── Payout Requests ── */

    /**
     * GET /api/v1/admin/financials/payouts
     */
    public function payouts(Request $request): JsonResponse
    {
        $result = $this->financialService->listPayouts([
            'status' => $request->query('status'),
            'recipient_type' => $request->query('recipient_type'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', 20),
        ]);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.payouts_fetched'),
            200,
            array_merge($result['meta'], ['summary' => $result['summary']])
        );
    }

    /**
     * POST /api/v1/admin/financials/payouts
     */
    public function storePayout(StoreAdminPayoutRequest $request): JsonResponse
    {
        $payout = $this->financialService->createPayoutRequest($this->authenticatedUser(), $request->validated());

        return $this->createdResponse($payout, __('messages.admin.payout_created'));
    }

    /**
     * POST /api/v1/admin/financials/payouts/{uuid}/approve
     */
    public function approvePayout(ProcessAdminPayoutRequest $request, string $uuid): JsonResponse
    {
        $payout = $this->financialService->approvePayout($this->authenticatedUser(), $uuid, $request->validated());

        return $this->successResponse($payout, __('messages.admin.payout_approved'));
    }

    /**
     * POST /api/v1/admin/financials/payouts/{uuid}/reject
     */
    public function rejectPayout(ProcessAdminPayoutRequest $request, string $uuid): JsonResponse
    {
        $payout = $this->financialService->rejectPayout($this->authenticatedUser(), $uuid, $request->validated());

        return $this->successResponse($payout, __('messages.admin.payout_rejected'));
    }

    /* ── Discount Coupons ── */

    /**
     * GET /api/v1/admin/financials/coupons
     */
    public function coupons(Request $request): JsonResponse
    {
        $result = $this->financialService->listCoupons([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page', 30),
        ]);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.coupons_fetched'),
            200,
            $result['meta']
        );
    }

    /**
     * POST /api/v1/admin/financials/coupons
     */
    public function storeCoupon(SaveAdminCouponRequest $request): JsonResponse
    {
        $coupon = $this->financialService->createCoupon($this->authenticatedUser(), $request->validated());

        return $this->createdResponse($coupon, __('messages.admin.coupon_created'));
    }

    /**
     * PUT /api/v1/admin/financials/coupons/{uuid}
     */
    public function updateCoupon(SaveAdminCouponRequest $request, string $uuid): JsonResponse
    {
        $coupon = $this->financialService->updateCoupon($this->authenticatedUser(), $uuid, $request->validated());

        return $this->successResponse($coupon, __('messages.admin.coupon_updated'));
    }

    /**
     * PATCH /api/v1/admin/financials/coupons/{uuid}/toggle-active
     */
    public function toggleCoupon(string $uuid): JsonResponse
    {
        $coupon = $this->financialService->toggleCouponActive($this->authenticatedUser(), $uuid);

        return $this->successResponse($coupon, __('messages.admin.coupon_toggled'));
    }

    /**
     * DELETE /api/v1/admin/financials/coupons/{uuid}
     */
    public function destroyCoupon(string $uuid): JsonResponse
    {
        $this->financialService->deleteCoupon($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.admin.coupon_deleted'));
    }
}
