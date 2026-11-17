<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Student\StudentBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentBillingController extends BaseApiController
{
    public function __construct(
        protected StudentBillingService $billingService
    ) {}

    public function overview(): JsonResponse
    {
        $data = $this->billingService->getBillingOverview($this->authenticatedUser());

        return $this->successResponse($data);
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'amount_egp' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->billingService->validateCoupon(
            $validated['code'],
            (float) ($validated['amount_egp'] ?? 0)
        );

        return $this->successResponse($result, 'كود الخصم صالح.');
    }

    public function storePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_uuid' => ['nullable', 'string'],
            'course_uuid' => ['nullable', 'string'],
            'payout_account_uuid' => ['nullable', 'string'],
            'payment_method' => ['required', 'string', 'max:30'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'receipt_image_path' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $this->billingService->createDirectPayment(
            $this->authenticatedUser(),
            $validated
        );

        return $this->createdResponse([
            'id' => $payment->uuid,
            'referenceNum' => $payment->reference_num,
            'amountEgp' => (float) $payment->amount_egp,
            'status' => $payment->status->value ?? (string) $payment->status,
            'paidAt' => $payment->paid_at?->toIso8601String(),
        ], 'تم إرسال طلب تأكيد الدفع بنجاح.');
    }
}
