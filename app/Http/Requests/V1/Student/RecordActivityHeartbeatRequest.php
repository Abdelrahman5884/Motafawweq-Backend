<?php

namespace App\Http\Requests\V1\Student;

use Illuminate\Foundation\Http\FormRequest;

class RecordActivityHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isStudent();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'delta_seconds' => ['nullable', 'integer', 'min:0', 'max:120'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            'is_check_in' => ['nullable', 'boolean'],
        ];
    }
}
