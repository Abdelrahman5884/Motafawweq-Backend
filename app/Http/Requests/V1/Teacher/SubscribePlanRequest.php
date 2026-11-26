<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\BillingPeriod;
use App\Enums\PaymentMethod;
use App\Enums\TeacherPlanTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SubscribePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTeacher() ?? false;
    }

    public function rules(): array
    {
        return [
            'plan_tier' => ['required', new Enum(TeacherPlanTier::class)],
            'billing_period' => ['required', new Enum(BillingPeriod::class)],
            'payment_method' => ['nullable', new Enum(PaymentMethod::class)],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'sender_phone' => ['nullable', 'string', 'max:40'],
            'sender_ipa' => ['nullable', 'string', 'max:120'],
            'transaction_ref' => ['nullable', 'string', 'max:120'],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ];
    }
}
