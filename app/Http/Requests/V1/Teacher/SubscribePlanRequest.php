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
            'plan_tier'      => ['required', new Enum(TeacherPlanTier::class)],
            'billing_period' => ['required', new Enum(BillingPeriod::class)],
            'payment_method' => ['required', new Enum(PaymentMethod::class)],
            'coupon_code'    => ['nullable', 'string', 'max:50'],
        ];
    }
}

