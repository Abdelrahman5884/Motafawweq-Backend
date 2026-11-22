<?php

namespace App\Http\Requests\V1\Center;

use App\Enums\DayOfWeek;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreCenterGroupRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'min:2', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'subject_ar' => ['required', 'string', 'min:2', 'max:120'],
            'subject_en' => ['nullable', 'string', 'max:120'],
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'grade_en' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'uuid'],
            'room_id' => ['nullable', 'uuid'],
            'center_teacher_id' => ['nullable', 'uuid'],
            'teacher_name_ar' => ['nullable', 'string', 'max:150'],
            'hall_name' => ['nullable', 'string', 'max:120'],
            'schedule_ar' => ['nullable', 'string', 'max:200'],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'price_egp' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'schedule_slots' => ['nullable', 'array', 'max:7'],
            'schedule_slots.*.day' => ['required_with:schedule_slots', 'string', Rule::in(DayOfWeek::values())],
            'schedule_slots.*.start_time' => ['required_with:schedule_slots', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'schedule_slots.*.end_time' => ['required_with:schedule_slots', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'schedule_slots.*.room_id' => ['nullable', 'uuid'],
            'schedule_slots.*.hall_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'nameAr' => 'name_ar',
            'nameEn' => 'name_en',
            'subjectAr' => 'subject_ar',
            'subjectEn' => 'subject_en',
            'gradeAr' => 'grade_ar',
            'gradeEn' => 'grade_en',
            'branchId' => 'branch_id',
            'roomId' => 'room_id',
            'teacherId' => 'center_teacher_id',
            'teacher_id' => 'center_teacher_id',
            'teacherNameAr' => 'teacher_name_ar',
            'hallName' => 'hall_name',
            'scheduleAr' => 'schedule_ar',
            'maxStudents' => 'max_students',
            'priceEgp' => 'price_egp',
            'scheduleSlots' => 'schedule_slots',
            'slots' => 'schedule_slots',
        ];

        $merged = [];
        foreach ($map as $alias => $canonical) {
            if (! $this->has($canonical) && $this->has($alias)) {
                $merged[$canonical] = $this->input($alias);
            }
        }

        $rawSlots = $merged['schedule_slots'] ?? $this->input('schedule_slots');
        if (is_array($rawSlots)) {
            $merged['schedule_slots'] = array_map(function ($s) {
                if (! is_array($s)) {
                    return $s;
                }

                return [
                    'day' => strtolower(trim((string) ($s['day'] ?? $s['day_of_week'] ?? ''))),
                    'start_time' => (string) ($s['start_time'] ?? $s['startTime'] ?? ''),
                    'end_time' => (string) ($s['end_time'] ?? $s['endTime'] ?? ''),
                    'room_id' => $s['room_id'] ?? $s['roomId'] ?? null,
                    'hall_name' => $s['hall_name'] ?? $s['hall'] ?? null,
                ];
            }, $rawSlots);
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }
}
