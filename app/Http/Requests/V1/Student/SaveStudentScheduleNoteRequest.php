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
            $merged['time'] = trim(strip_tags((string) $time));
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
            'time' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', 'string', Rule::in(['low', 'medium', 'high'])],
        ];
    }
}
