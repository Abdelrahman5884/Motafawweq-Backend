<?php

namespace App\Http\Requests\V1\Center;

use App\Http\Requests\BaseApiRequest;

class StoreCenterRoomRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'min:2', 'max:180'],
            'name_en' => ['nullable', 'string', 'max:180'],
            'branch_id' => ['nullable', 'uuid'],
            'floor' => ['nullable', 'string', 'max:80'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'equipment' => ['nullable', 'array', 'max:20'],
            'equipment.*' => ['string', 'max:120'],
            'is_available' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'nameAr' => 'name_ar',
            'nameEn' => 'name_en',
            'branchId' => 'branch_id',
            'equipped' => 'equipment',
            'isAvailable' => 'is_available',
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
