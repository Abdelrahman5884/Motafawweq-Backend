<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;

class InviteStudentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $studentId = $this->input('student_id') ?? $this->input('studentId') ?? $this->input('studentUuid');
        $nameAr = $this->input('name_ar') ?? $this->input('nameAr') ?? $this->input('name');
        $phone = $this->input('phone');
        $parentNameAr = $this->input('parent_name_ar') ?? $this->input('parentNameAr') ?? $this->input('parentName');
        $parentPhone = $this->input('parent_phone') ?? $this->input('parentPhone');

        $merged = [];
        if ($studentId !== null) {
            $merged['student_id'] = trim((string) $studentId);
        }
        if ($nameAr !== null) {
            $merged['name_ar'] = trim(strip_tags((string) $nameAr));
        }
        if ($phone !== null) {
            $merged['phone'] = trim((string) $phone);
        }
        if ($parentNameAr !== null) {
            $merged['parent_name_ar'] = trim(strip_tags((string) $parentNameAr));
        }
        if ($parentPhone !== null) {
            $merged['parent_phone'] = trim((string) $parentPhone);
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'student_id'     => ['nullable', 'string', 'max:64'],
            'name_ar'        => ['required_without:student_id', 'nullable', 'string', 'min:2', 'max:150'],
            'phone'          => ['required_without:student_id', 'nullable', 'string', 'min:8', 'max:30'],
            'parent_name_ar' => ['nullable', 'string', 'max:150'],
            'parent_phone'   => ['nullable', 'string', 'max:30'],
        ];
    }
}

