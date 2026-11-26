<?php

namespace App\Http\Requests\Api\V1\Parent;

use Illuminate\Foundation\Http\FormRequest;

class LinkChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isParent() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('student_code') ?? $this->input('studentCode') ?? $this->input('code');

        if (is_string($code)) {
            $this->merge([
                'student_code' => strtoupper(trim($code)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'student_code' => ['required', 'string', 'min:3', 'max:64'],
        ];
    }
}
