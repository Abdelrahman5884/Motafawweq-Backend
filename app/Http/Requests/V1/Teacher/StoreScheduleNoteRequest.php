<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\DayOfWeek;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreScheduleNoteRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $day = $this->input('day') ?? $this->input('day_of_week');
        $text = $this->input('text') ?? $this->input('note_text');
        $time = $this->input('time') ?? $this->input('note_time');
        $priority = $this->input('priority');

        $merged = [];
        if ($day !== null) {
            $merged['day'] = strtolower(trim((string) $day));
        }
        if ($text !== null) {
            $merged['text'] = trim(strip_tags((string) $text));
        }
        if ($time !== null) {
            $merged['time'] = trim((string) $time);
        }
        if ($priority !== null) {
            $merged['priority'] = strtolower(trim((string) $priority));
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'day'      => ['required', 'string', Rule::in(DayOfWeek::values())],
            'text'     => ['required', 'string', 'min:1', 'max:500'],
            'time'     => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'priority' => ['nullable', 'string', Rule::in(['high', 'medium', 'normal', 'low'])],
        ];
    }
}

