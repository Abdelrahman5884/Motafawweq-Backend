<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\AttendanceStatus;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class ToggleAttendanceRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $enrollmentId = $this->input('enrollment_id')
            ?? $this->input('enrollmentId')
            ?? $this->input('enrollmentUuid')
            ?? $this->input('student_id')
            ?? $this->input('studentId');

        $status = $this->input('status');
        $date = $this->input('date');
        $notes = $this->input('notes');

        $merged = [];
        if ($enrollmentId !== null) {
            $merged['enrollment_id'] = trim((string) $enrollmentId);
        }
        if ($status !== null) {
            $merged['status'] = strtolower(trim((string) $status));
        }
        if ($date !== null) {
            $merged['date'] = trim((string) $date);
        }
        if ($notes !== null) {
            $merged['notes'] = trim(strip_tags((string) $notes));
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'enrollment_id' => ['required', 'string', 'max:64'],
            'status'        => ['nullable', 'string', Rule::in(AttendanceStatus::values())],
            'date'          => ['nullable', 'date_format:Y-m-d'],
            'notes'         => ['nullable', 'string', 'max:255'],
        ];
    }
}

