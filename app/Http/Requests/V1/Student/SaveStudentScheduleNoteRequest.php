<?php

namespace App\Http\Requests\V1\Student;

use App\Enums\DayOfWeek;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class SaveStudentScheduleNoteRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStudent();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $day = $this->input('day_of_week') ?? $this->input('day');
        $text = $this->input('text');
        $time = $this->input('time');
        $priority = $this->input('priority');

        $merged = [];
        if ($day !== null) {
            $merged['day_of_week'] = strtolower(trim((string) $day));
        }
        if ($text !== null) {
            $merged['text'] = trim(strip_tags((string) $text));
        }
        if ($time !== null) {
            $cleanTime = trim(strip_tags((string) $time));
            $merged['time'] = $cleanTime === '' ? null : $cleanTime;
        }
        if ($priority !== null) {
            $merged['priority'] = strtolower(trim((string) $priority));
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'string', Rule::in(DayOfWeek::values())],
            'text' => ['required', 'string', 'min:1', 'max:500'],
            'time' => ['nullable', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'priority' => ['nullable', 'string', Rule::in(['low', 'medium', 'high'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day_of_week.required' => 'يرجى تحديد يوم الملحوظة.',
            'day_of_week.in' => 'اليوم المحدد غير صالح.',
            'text.required' => 'يرجى كتابة نص الملحوظة.',
            'text.max' => 'نص الملحوظة يجب ألا يتجاوز 500 حرف.',
            'time.regex' => 'صيغة الوقت غير صالحة (HH:MM).',
            'priority.in' => 'مستوى الأولوية المحدد غير صالح.',
        ];
    }
}
