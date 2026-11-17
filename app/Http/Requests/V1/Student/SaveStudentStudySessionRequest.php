<?php

namespace App\Http\Requests\V1\Student;

use App\Enums\DayOfWeek;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class SaveStudentStudySessionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStudent();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $day = $this->input('day_of_week') ?? $this->input('day');
        $subjectAr = $this->input('subject_ar') ?? $this->input('subjectAr') ?? $this->input('subject');
        $titleAr = $this->input('title_ar') ?? $this->input('titleAr') ?? $this->input('title');
        $startTime = $this->input('start_time') ?? $this->input('startTime');
        $endTime = $this->input('end_time') ?? $this->input('endTime');

        $merged = [];
        if ($day !== null) {
            $merged['day_of_week'] = strtolower(trim((string) $day));
        }
        if ($subjectAr !== null) {
            $merged['subject_ar'] = trim(strip_tags((string) $subjectAr));
        }
        if ($titleAr !== null) {
            $merged['title_ar'] = trim(strip_tags((string) $titleAr));
        }
        if ($startTime !== null) {
            $merged['start_time'] = trim((string) $startTime);
        }
        if ($endTime !== null) {
            $merged['end_time'] = trim((string) $endTime);
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'string', Rule::in(DayOfWeek::values())],
            'subject_ar' => ['required', 'string', 'min:2', 'max:120'],
            'title_ar' => ['required', 'string', 'min:2', 'max:200'],
            'start_time' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'end_time' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'color' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
