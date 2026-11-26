<?php

namespace App\Http\Requests\Api\V1\Parent;

use Illuminate\Foundation\Http\FormRequest;

class StoreParentInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isParent() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $studentId = $this->input('student_id') ?? $this->input('studentId');
        $teacherId = $this->input('teacher_id') ?? $this->input('teacherId');
        $recipientType = $this->input('recipient_type') ?? $this->input('recipientType') ?? 'center';

        $this->merge([
            'student_id' => $studentId !== null && $studentId !== '' ? (int) $studentId : null,
            'teacher_id' => $teacherId !== null && $teacherId !== '' ? (int) $teacherId : null,
            'recipient_type' => is_string($recipientType) ? strtolower(trim($recipientType)) : 'center',
            'subject' => is_string($this->input('subject')) ? trim(strip_tags($this->input('subject'))) : $this->input('subject'),
            'message' => is_string($this->input('message')) ? trim(strip_tags($this->input('message'))) : $this->input('message'),
        ]);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['nullable', 'integer', 'min:1'],
            'teacher_id' => ['nullable', 'integer', 'min:1'],
            'recipient_type' => ['required', 'string', 'in:center,teacher,both'],
            'subject' => ['required', 'string', 'min:3', 'max:190'],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
