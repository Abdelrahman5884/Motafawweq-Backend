<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class SaveAdminSubscriptionPlanRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'target_role' => $this->input('target_role', $this->input('targetRole')),
            'tier_key' => $this->input('tier_key', $this->input('tierKey')),
            'name_ar' => $this->input('name_ar', $this->input('nameAr')),
            'name_en' => $this->input('name_en', $this->input('nameEn')),
            'billing_cycle' => $this->input('billing_cycle', $this->input('billingCycle')),
            'price_egp' => $this->input('price_egp', $this->input('priceEgp')),
            'term_price_egp' => $this->input('term_price_egp', $this->input('termPriceEgp')),
            'annual_price_egp' => $this->input('annual_price_egp', $this->input('annualPriceEgp')),
            'commission_percent' => $this->input('commission_percent', $this->input('commissionPercent')),
            'badge_ar' => $this->input('badge_ar', $this->input('badgeAr')),
            'badge_en' => $this->input('badge_en', $this->input('badgeEn')),
            'is_popular' => $this->has('is_popular')
                ? $this->input('is_popular')
                : ($this->has('isPopular') ? $this->input('isPopular') : null),
            'is_active' => $this->has('is_active')
                ? $this->input('is_active')
                : ($this->has('isActive') ? $this->input('isActive') : null),
            'features_ar' => $this->input('features_ar', $this->input('featuresAr')),
            'features_en' => $this->input('features_en', $this->input('featuresEn')),
            'display_order' => $this->input('display_order', $this->input('displayOrder')),
        ];

        $this->merge(array_filter($map, fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $req = $isUpdate ? ['sometimes', 'required'] : ['required'];

        return [
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
            'target_role' => [...$req, 'string', Rule::in(['center', 'teacher', 'student'])],
            'tier_key' => ['sometimes', 'string', Rule::in(['free', 'plus', 'pro', 'custom'])],
            'name_ar' => [...$req, 'string', 'min:2', 'max:180'],
            'name_en' => ['nullable', 'string', 'max:180'],
            'billing_cycle' => ['sometimes', 'string', Rule::in(['monthly', 'term', 'annual'])],
            'price_egp' => [...$req, 'numeric', 'min:0', 'max:500000'],
            'term_price_egp' => ['nullable', 'numeric', 'min:0', 'max:2000000'],
            'annual_price_egp' => ['sometimes', 'numeric', 'min:0', 'max:5000000'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'badge_ar' => ['nullable', 'string', 'max:120'],
            'badge_en' => ['nullable', 'string', 'max:120'],
            'is_popular' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'features_ar' => ['nullable', 'array', 'max:30'],
            'features_ar.*' => ['string', 'max:260'],
            'features_en' => ['nullable', 'array', 'max:30'],
            'features_en.*' => ['string', 'max:260'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
