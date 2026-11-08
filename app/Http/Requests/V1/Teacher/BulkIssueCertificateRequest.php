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
            'course_id'       => ['nullable', 'string'],
            'course_name_ar'  => ['required', 'string', 'max:200'],
            'course_name_en'  => ['nullable', 'string', 'max:200'],
            'grade_ar'        => ['nullable', 'string', 'max:100'],
            'honors_title_ar' => ['required', 'string', 'max:255'],
            'students'        => ['required', 'array', 'min:1', 'max:100'],
            'students.*.student_name_ar' => ['required', 'string', 'max:150'],
            'students.*.grade_percent'   => ['required', 'numeric', 'min:0', 'max:100'],
            'students.*.student_id'      => ['nullable', 'integer'],
            'students.*.student_email'   => ['nullable', 'email'],
            'students.*.student_phone'   => ['nullable', 'string'],
        ];
    }
}

