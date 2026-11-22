<?php

namespace App\Http\Requests\V1\Teacher;

use Illuminate\Foundation\Http\FormRequest;

class BulkIssueCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTeacher() ?? false;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['nullable', 'max:64'],
            'course_name_ar' => ['required', 'string', 'max:200'],
            'course_name_en' => ['nullable', 'string', 'max:200'],
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'honors_title_ar' => ['required', 'string', 'max:255'],
            'honors_title_en' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['nullable', 'date'],
            'students' => ['required', 'array', 'min:1', 'max:100'],
            'students.*.student_name_ar' => ['required', 'string', 'max:150'],
            'students.*.student_name_en' => ['nullable', 'string', 'max:150'],
            'students.*.grade_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'students.*.grade_ar' => ['nullable', 'string', 'max:100'],
            'students.*.student_id' => ['nullable', 'max:64'],
            'students.*.student_email' => ['nullable', 'email', 'max:150'],
            'students.*.student_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
