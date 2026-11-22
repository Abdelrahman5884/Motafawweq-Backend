<?php

namespace App\Http\Requests\V1\Center;

use App\Http\Requests\BaseApiRequest;

class StoreCenterTeacherRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'min:2', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'subject_ar' => ['required', 'string', 'min:2', 'max:150'],
            'subject_en' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'branch_id' => ['nullable', 'uuid'],
            'settlement_rate' => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'nameAr' => 'name_ar',
            'nameEn' => 'name_en',
            'subjectAr' => 'subject_ar',
            'subjectEn' => 'subject_en',
            'branchId' => 'branch_id',
            'settlementRate' => 'settlement_rate',
        ];

        $merged = [];
        foreach ($map as $camel => $snake) {
            if (! $this->has($snake) && $this->has($camel)) {
                $merged[$snake] = $this->input($camel);
            }
        }
        if ($this->filled('email')) {
            $merged['email'] = strtolower(trim((string) $this->input('email')));
        }
        if (! empty($merged)) {
            $this->merge($merged);
        }
    }
}
