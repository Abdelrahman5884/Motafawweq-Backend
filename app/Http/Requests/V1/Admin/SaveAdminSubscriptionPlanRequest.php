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

        $aliases = [
            'target_role' => 'targetRole',
            'tier_key' => 'tierKey',
            'name_ar' => 'nameAr',
            'name_en' => 'nameEn',
            'billing_cycle' => 'billingCycle',
            'price_egp' => 'priceEgp',
            'original_price_egp' => 'originalPriceEgp',
            'term_price_egp' => 'termPriceEgp',
            'original_term_price_egp' => 'originalTermPriceEgp',
            'annual_price_egp' => 'annualPriceEgp',
            'original_annual_price_egp' => 'originalAnnualPriceEgp',
            'discount_percent' => 'discountPercent',
            'commission_percent' => 'commissionPercent',
            'badge_ar' => 'badgeAr',
            'badge_en' => 'badgeEn',
            'is_popular' => 'isPopular',
            'is_active' => 'isActive',
            'free_promo_enabled' => 'freePromoEnabled',
            'free_promo_until' => 'freePromoUntil',
            'free_promo_label_ar' => 'freePromoLabelAr',
            'free_promo_label_en' => 'freePromoLabelEn',
            'features_ar' => 'featuresAr',
            'features_en' => 'featuresEn',
            'display_order' => 'displayOrder',
        ];

        $merged = [];
        foreach ($aliases as $snake => $camel) {
            if ($this->exists($snake)) {
                $val = $this->input($snake);
                $merged[$snake] = $val === '' ? null : $val;
            } elseif ($this->exists($camel)) {
                $val = $this->input($camel);
                $merged[$snake] = $val === '' ? null : $val;
            }
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
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
            'original_price_egp' => ['nullable', 'numeric', 'min:0', 'max:500000'],
            'term_price_egp' => ['nullable', 'numeric', 'min:0', 'max:2000000'],
            'original_term_price_egp' => ['nullable', 'numeric', 'min:0', 'max:2000000'],
            'annual_price_egp' => ['sometimes', 'numeric', 'min:0', 'max:5000000'],
            'original_annual_price_egp' => ['nullable', 'numeric', 'min:0', 'max:5000000'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:99.9'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'badge_ar' => ['nullable', 'string', 'max:120'],
            'badge_en' => ['nullable', 'string', 'max:120'],
            'is_popular' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'free_promo_enabled' => ['sometimes', 'boolean'],
            'free_promo_until' => ['nullable', 'date'],
            'free_promo_label_ar' => ['nullable', 'string', 'max:140'],
            'free_promo_label_en' => ['nullable', 'string', 'max:140'],
            'features_ar' => ['nullable', 'array', 'max:30'],
            'features_ar.*' => ['string', 'max:260'],
            'features_en' => ['nullable', 'array', 'max:30'],
            'features_en.*' => ['string', 'max:260'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
