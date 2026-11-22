<?php

namespace App\Http\Requests\V1\Center;

use App\Enums\BillingPeriod;
use App\Enums\PaymentMethod;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class SubscribeCenterPlanRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'plan_tier' => ['required', 'string', Rule::in(['free', 'plus', 'pro', 'plan-center-free', 'plan-center-plus', 'plan-center-pro'])],
            'billing_period' => ['nullable', 'string', Rule::in(array_column(BillingPeriod::cases(), 'value'))],
            'payment_method' => ['nullable', 'string', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'sender_phone' => ['nullable', 'string', 'max:40'],
            'sender_ipa' => ['nullable', 'string', 'max:100'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'planTier' => 'plan_tier',
            'planId' => 'plan_tier',
            'billingPeriod' => 'billing_period',
            'paymentMethod' => 'payment_method',
            'couponCode' => 'coupon_code',
            'senderPhone' => 'sender_phone',
            'senderIpa' => 'sender_ipa',
            'transactionRef' => 'transaction_ref',
        ];

        $merged = [];
        foreach ($map as $camel => $snake) {
            if (! $this->has($snake) && $this->has($camel)) {
                $merged[$snake] = $this->input($camel);
            }
        }
        if (! empty($merged)) {
            $this->merge($merged);
        }
    }
}
