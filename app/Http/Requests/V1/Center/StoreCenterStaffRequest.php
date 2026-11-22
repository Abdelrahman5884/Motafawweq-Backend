<?php

namespace App\Http\Requests\V1\Center;

use App\Enums\CenterStaffRole;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreCenterStaffRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'min:2', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'role' => ['nullable', 'string', Rule::in(CenterStaffRole::values())],
            'role_ar' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'uuid'],
            'salary_egp' => ['nullable', 'numeric', 'min:0', 'max:500000'],
            'permissions' => ['nullable', 'array', 'max:20'],
            'permissions.*' => ['string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'nameAr' => 'name_ar',
            'nameEn' => 'name_en',
            'roleAr' => 'role_ar',
            'branchId' => 'branch_id',
            'salaryEgp' => 'salary_egp',
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
