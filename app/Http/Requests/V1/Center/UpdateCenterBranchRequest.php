<?php

namespace App\Http\Requests\V1\Center;

use App\Http\Requests\BaseApiRequest;

class UpdateCenterBranchRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'min:2', 'max:180'],
            'name_en' => ['nullable', 'string', 'max:180'],
            'address_ar' => ['nullable', 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'manager_name_ar' => ['nullable', 'string', 'max:150'],
            'manager_name_en' => ['nullable', 'string', 'max:150'],
            'is_main' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'nameAr' => 'name_ar',
            'nameEn' => 'name_en',
            'addressAr' => 'address_ar',
            'addressEn' => 'address_en',
            'managerNameAr' => 'manager_name_ar',
            'managerNameEn' => 'manager_name_en',
            'isMain' => 'is_main',
            'isActive' => 'is_active',
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
