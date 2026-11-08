<?php

namespace App\Http\Requests\V1\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class ValidateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTeacher() ?? false;
    }

    public function rules(): array
    {
        return [
            'code'         => ['required', 'string', 'max:50'],
            'order_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}

