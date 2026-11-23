<?php

namespace App\Http\Requests\V1\Center;

use App\Enums\CenterRefundPolicy;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateCenterSettingsRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'min:2', 'max:180'],
            'name_en' => ['nullable', 'string', 'max:180'],
            'manager_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address_ar' => ['nullable', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:255'],
            'working_hours' => ['nullable', 'string', 'max:120'],
            'late_margin_minutes' => ['sometimes', 'integer', 'min:5', 'max:60'],
            'sibling_discount_percent' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'mandatory_cash_close' => ['sometimes', 'boolean'],
            'refund_policy' => ['sometimes', 'string', Rule::in(CenterRefundPolicy::values())],
            'default_teacher_settlement_rate' => ['sometimes', 'numeric', 'min:0.05', 'max:0.95'],
            'payment_methods' => ['sometimes', 'array'],
            'payment_methods.cash' => ['sometimes', 'boolean'],
            'payment_methods.vodafoneCash' => ['sometimes', 'boolean'],
            'payment_methods.instaPay' => ['sometimes', 'boolean'],
            'payment_methods.visa' => ['sometimes', 'boolean'],
            'custom_domain' => ['nullable', 'string', 'max:180', 'regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'],
            'preferred_theme' => ['sometimes', 'string', Rule::in(['light', 'dark'])],
            'preferred_lang' => ['sometimes', 'string', Rule::in(['ar', 'en'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'centerNameAr' => 'name_ar',
            'nameAr' => 'name_ar',
            'centerNameEn' => 'name_en',
            'nameEn' => 'name_en',
            'managerName' => 'manager_name',
            'centerPhone' => 'phone',
            'centerAddress' => 'address_ar',
            'addressAr' => 'address_ar',
            'centerAddressEn' => 'address_en',
            'addressEn' => 'address_en',
            'workingHours' => 'working_hours',
            'workingHoursAr' => 'working_hours',
            'lateMarginMinutes' => 'late_margin_minutes',
            'siblingDiscountPercent' => 'sibling_discount_percent',
            'mandatoryCashClose' => 'mandatory_cash_close',
            'refundPolicy' => 'refund_policy',
            'defaultTeacherSettlementRate' => 'default_teacher_settlement_rate',
            'paymentMethods' => 'payment_methods',
            'customDomain' => 'custom_domain',
            'preferredTheme' => 'preferred_theme',
            'preferredLang' => 'preferred_lang',
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
