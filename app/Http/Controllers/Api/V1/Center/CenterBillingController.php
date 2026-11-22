<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\SubscribeCenterPlanRequest;
use App\Services\V1\Center\CenterBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CenterBillingController extends BaseApiController
{
    public function __construct(
        protected CenterBillingService $billingService
    ) {}

    public function index(): JsonResponse
    {
        $summary = $this->billingService->getBillingSummary($this->authenticatedUser());

        return $this->successResponse($summary, __('messages.billing.retrieved'));
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'order_amount' => $request->input('order_amount', $request->input('orderAmount')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'order_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $result = $this->billingService->validateCoupon(
            $validated['code'],
            (float) ($validated['order_amount'] ?? 0.0)
        );

        if (! $result['isValid']) {
            return $this->validationErrorResponse(
                ['code' => [$result['messageAr']]],
                $result['messageAr']
            );
        }

        return $this->successResponse($result, $result['messageAr']);
    }

    public function subscribe(SubscribeCenterPlanRequest $request): JsonResponse
    {
        $result = $this->billingService->subscribeOrUpgrade(
            $this->authenticatedUser(),
            $request->validated()
        );

        $inv = $result['invoice'];

        return $this->successResponse([
            'activeSub' => $result['activeSub'],
            'usage' => $result['usage'],
            'invoice' => [
                'id' => $inv->invoice_number,
                'uuid' => $inv->uuid,
                'invoiceNumber' => $inv->invoice_number,
                'date' => ($inv->paid_at ?? $inv->created_at)?->format('Y-m-d'),
                'descriptionAr' => $inv->description_ar,
                'descriptionEn' => $inv->description_en,
                'baseAmountEgp' => (float) $inv->base_amount_egp,
                'discountAmountEgp' => (float) $inv->discount_amount_egp,
                'finalAmountEgp' => (float) $inv->final_amount_egp,
                'couponCode' => $inv->coupon_code,
                'paymentMethod' => $inv->payment_method?->value,
                'status' => $inv->status?->value,
            ],
        ], $result['messageAr']);
    }

    public function cancel(): JsonResponse
    {
        $result = $this->billingService->cancelSubscription($this->authenticatedUser());

        return $this->successResponse([
            'activeSub' => $result['activeSub'],
            'usage' => $result['usage'],
            'previousPlan' => $result['previousPlan'],
        ], $result['messageAr']);
    }
}
