<?php

namespace App\Http\Requests\V1\Student;

use App\Http\Requests\BaseApiRequest;

class RecordLessonProgressRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStudent();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:14400'],
            'watched_delta_seconds' => ['nullable', 'integer', 'min:0', 'max:60'],
            'duration_seconds' => ['nullable', 'integer', 'min:10', 'max:14400'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'position_seconds.integer' => 'موضع المشاهدة غير صالح.',
            'watched_delta_seconds.max' => 'مدة التحديث تتجاوز الحد المسموح به.',
            'duration_seconds.min' => 'مدة الحصة غير صالحة.',
        ];
    }
}
