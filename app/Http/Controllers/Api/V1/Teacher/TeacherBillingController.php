<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Teacher\SubscribePlanRequest;
use App\Http\Requests\V1\Teacher\ValidateCouponRequest;
use App\Http\Resources\V1\Teacher\TeacherInvoiceResource;
use App\Services\V1\Teacher\TeacherBillingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherBillingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TeacherBillingService $billingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $summary = $this->billingService->getBillingSummary($request->user());

        return $this->success($summary, __('messages.billing.retrieved'));
    }

    public function validateCoupon(ValidateCouponRequest $request): JsonResponse
    {
        $code = (string) $request->validated('code');
        $orderAmount = (float) $request->validated('order_amount', 0.0);

        $result = $this->billingService->validateCoupon($code, $orderAmount);

        if (!$result['isValid']) {
            return $this->validationError(
                ['code' => [$result['messageAr']]],
                $result['messageAr']
            );
        }

        return $this->success($result, $result['messageAr']);
    }

    public function subscribe(SubscribePlanRequest $request): JsonResponse
    {
        $result = $this->billingService->subscribeOrUpgrade(
            $request->user(),
            $request->validated()
        );

        return $this->success([
            'activeSub'     => [
                'planTier'      => $result['subscription']->plan_tier->value,
                'planNameAr'    => $result['subscription']->plan_name_ar,
                'planNameEn'    => $result['subscription']->plan_name_en,
                'billingPeriod' => $result['subscription']->billing_period->value,
                'status'        => $result['subscription']->status,
                'renewalDate'   => $result['subscription']->renews_at->toDateString(),
                'daysRemaining' => $result['daysRemaining'],
                'amountEgp'     => $result['subscription']->amount_egp,
            ],
            'invoice'       => new TeacherInvoiceResource($result['invoice']),
        ], $result['messageAr']);
    }
}

