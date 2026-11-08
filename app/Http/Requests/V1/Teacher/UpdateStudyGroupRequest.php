<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\DayOfWeek;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateStudyGroupRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $nameAr = $this->input('name_ar') ?? $this->input('nameAr') ?? $this->input('name');
        $subjectAr = $this->input('subject_ar') ?? $this->input('subjectAr') ?? $this->input('subject');
        $gradeAr = $this->input('grade_ar') ?? $this->input('gradeAr') ?? $this->input('grade');
        $centerName = $this->input('center_name') ?? $this->input('centerName');
        $hallName = $this->input('hall_name') ?? $this->input('hallName');
        $priceEgp = $this->input('price_egp') ?? $this->input('priceEgp');
        $maxStudents = $this->input('max_students') ?? $this->input('maxStudents');

        $merged = [];
        if ($nameAr !== null) {
            $merged['name_ar'] = trim(strip_tags((string) $nameAr));
        }
        if ($subjectAr !== null) {
            $merged['subject_ar'] = trim(strip_tags((string) $subjectAr));
        }
        if ($gradeAr !== null) {
            $merged['grade_ar'] = trim(strip_tags((string) $gradeAr));
        }
        if ($centerName !== null) {
            $merged['center_name'] = trim(strip_tags((string) $centerName));
        }
        if ($hallName !== null) {
            $merged['hall_name'] = trim(strip_tags((string) $hallName));
        }
        if ($priceEgp !== null) {
            $merged['price_egp'] = (float) $priceEgp;
        }
        if ($maxStudents !== null) {
            $merged['max_students'] = (int) $maxStudents;
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'name_ar'         => ['sometimes', 'string', 'min:3', 'max:200'],
            'nameAr'          => ['nullable', 'string', 'max:200'],
            'nameEn'          => ['nullable', 'string', 'max:200'],
            'name_en'         => ['nullable', 'string', 'max:200'],
            'subject_ar'      => ['sometimes', 'string', 'min:2', 'max:120'],
            'subjectAr'       => ['nullable', 'string', 'max:120'],
            'subjectEn'       => ['nullable', 'string', 'max:120'],
            'subject_en'      => ['nullable', 'string', 'max:120'],
            'grade_ar'        => ['sometimes', 'string', 'max:100'],
            'gradeAr'         => ['nullable', 'string', 'max:100'],
            'gradeEn'         => ['nullable', 'string', 'max:100'],
            'grade_en'        => ['nullable', 'string', 'max:100'],
            'center_name'     => ['sometimes', 'string', 'min:2', 'max:150'],
            'centerName'      => ['nullable', 'string', 'max:150'],
            'hall_name'       => ['nullable', 'string', 'max:100'],
            'hallName'        => ['nullable', 'string', 'max:100'],
            'schedule_ar'     => ['nullable', 'string', 'max:200'],
            'scheduleAr'      => ['nullable', 'string', 'max:200'],
            'schedule_en'     => ['nullable', 'string', 'max:200'],
            'scheduleEn'      => ['nullable', 'string', 'max:200'],
            'max_students'    => ['nullable', 'integer', 'min:1', 'max:1000'],
            'maxStudents'     => ['nullable', 'integer', 'min:1', 'max:1000'],
            'price_egp'       => ['nullable', 'numeric', 'min:0', 'max:50000'],
            'priceEgp'        => ['nullable', 'numeric', 'min:0', 'max:50000'],
            'status'          => ['nullable', 'string', Rule::in(['active', 'archived'])],
            'scheduleSlots'   => ['nullable', 'array', 'max:14'],
            'schedule_slots'  => ['nullable', 'array', 'max:14'],
            'slots'           => ['nullable', 'array', 'max:14'],
            'scheduleSlots.*.day'       => ['required_with:scheduleSlots', 'string', Rule::in(DayOfWeek::values())],
            'scheduleSlots.*.startTime' => ['required_with:scheduleSlots', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'scheduleSlots.*.endTime'   => ['required_with:scheduleSlots', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'scheduleSlots.*.hall'      => ['nullable', 'string', 'max:100'],
        ];
    }
}

