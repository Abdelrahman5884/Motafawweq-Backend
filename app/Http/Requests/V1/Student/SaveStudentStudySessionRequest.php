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
        $color = $this->input('color');
        $notes = $this->input('notes');

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
        if ($color !== null) {
            $trimmedColor = trim((string) $color);
            $merged['color'] = $trimmedColor === '' ? null : strtoupper($trimmedColor);
        }
        if ($notes !== null) {
            $trimmedNotes = trim(strip_tags((string) $notes));
            $merged['notes'] = $trimmedNotes === '' ? null : $trimmedNotes;
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
            'subject_ar' => ['required', 'string', 'min:2', 'max:120'],
            'title_ar' => ['required', 'string', 'min:2', 'max:200'],
            'start_time' => ['required', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['required', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day_of_week.required' => 'يرجى تحديد يوم المذاكرة.',
            'day_of_week.in' => 'اليوم المحدد غير صالح.',
            'subject_ar.required' => 'يرجى اختيار المادة الدراسية.',
            'subject_ar.min' => 'اسم المادة يجب ألا يقل عن حرفين.',
            'subject_ar.max' => 'اسم المادة يجب ألا يتجاوز 120 حرفاً.',
            'title_ar.required' => 'يرجى كتابة موضوع جلسة المذاكرة.',
            'title_ar.min' => 'موضوع الجلسة يجب ألا يقل عن حرفين.',
            'title_ar.max' => 'موضوع الجلسة يجب ألا يتجاوز 200 حرف.',
            'start_time.required' => 'يرجى تحديد وقت بداية الجلسة.',
            'start_time.regex' => 'صيغة وقت البداية غير صالحة (HH:MM).',
            'end_time.required' => 'يرجى تحديد وقت نهاية الجلسة.',
            'end_time.regex' => 'صيغة وقت النهاية غير صالحة (HH:MM).',
            'color.regex' => 'لون الجلسة غير صالح.',
            'notes.max' => 'الملاحظات يجب ألا تتجاوز 500 حرف.',
        ];
    }
}
