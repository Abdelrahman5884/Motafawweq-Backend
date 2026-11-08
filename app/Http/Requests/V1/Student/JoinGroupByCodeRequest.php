<?php

namespace App\Http\Requests\V1\Student;

use App\Http\Requests\BaseApiRequest;

class JoinGroupByCodeRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $joinCode = $this->input('join_code') ?? $this->input('joinCode') ?? $this->input('code');

        if ($joinCode !== null) {
            $this->merge([
                'join_code' => strtoupper(trim((string) $joinCode)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'join_code'      => ['required', 'string', 'min:4', 'max:32', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'parent_name_ar' => ['nullable', 'string', 'max:150'],
            'parent_phone'   => ['nullable', 'string', 'max:30'],
        ];
    }
}

