<?php

namespace App\Http\Requests\V1\Center;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class EnrollCenterStudentRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'student_id' => ['nullable', 'string', 'max:40'],
            'name_ar' => ['required_without:student_id', 'nullable', 'string', 'min:2', 'max:150'],
            'phone' => ['required_without:student_id', 'nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'parent_name_ar' => ['nullable', 'string', 'max:150'],
            'parent_phone' => ['nullable', 'string', 'max:40'],
            'payment_status' => ['nullable', 'string', Rule::in(['paid', 'partial', 'due'])],
            'due_amount_egp' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'studentId' => 'student_id',
            'nameAr' => 'name_ar',
            'gradeAr' => 'grade_ar',
            'parentNameAr' => 'parent_name_ar',
            'parentPhone' => 'parent_phone',
            'paymentStatus' => 'payment_status',
            'dueAmountEgp' => 'due_amount_egp',
        ];

        $merged = [];
        foreach ($map as $camel => $snake) {
            if (! $this->has($snake) && $this->has($camel)) {
                $merged[$snake] = $this->input($camel);
            }
        }
        if (isset($merged['student_id'])) {
            $merged['student_id'] = (string) $merged['student_id'];
        } elseif ($this->has('student_id') && is_numeric($this->input('student_id'))) {
            $merged['student_id'] = (string) $this->input('student_id');
        }
        if (! empty($merged)) {
            $this->merge($merged);
        }
    }
}
