<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class SaveAdminCouponRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $code = $this->has('code') ? strtoupper(trim((string) $this->input('code'))) : null;

        $this->merge(array_filter([
            'code' => $code,
            'discount_percent' => $this->input('discount_percent', $this->input('discountPercent')),
            'max_discount_egp' => $this->input('max_discount_egp', $this->input('maxDiscountEgp')),
            'min_order_egp' => $this->input('min_order_egp', $this->input('minOrderEgp')),
            'max_uses' => $this->input('max_uses', $this->input('maxUses')),
            'expires_at' => $this->input('expires_at', $this->input('expiresAt', $this->input('expiryDate'))),
            'is_active' => $this->has('is_active')
                ? $this->input('is_active')
                : ($this->has('isActive') ? $this->input('isActive') : null),
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $uuid = (string) $this->route('uuid');

        $uniqueRule = Rule::unique('discount_coupons', 'code');
        if ($isUpdate && $uuid !== '') {
            $uniqueRule = $uniqueRule->ignore($uuid, 'uuid');
        }

        return [
            'code' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'min:3',
                'max:50',
                'regex:/^[A-Z0-9_\-]+$/',
                $uniqueRule,
            ],
            'discount_percent' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:1', 'max:100'],
            'max_discount_egp' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'min_order_egp' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
