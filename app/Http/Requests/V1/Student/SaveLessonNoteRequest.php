<?php

namespace App\Http\Requests\V1\Student;

use App\Http\Requests\BaseApiRequest;

class SaveLessonNoteRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStudent();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $content = $this->input('content');
        $timestamp = $this->input('timestamp_seconds') ?? $this->input('timestampSeconds');

        $merged = [];
        if ($content !== null) {
            $merged['content'] = trim(strip_tags((string) $content));
        }
        if ($timestamp !== null && $timestamp !== '') {
            $merged['timestamp_seconds'] = max(0, (int) $timestamp);
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
            'timestamp_seconds' => ['nullable', 'integer', 'min:0', 'max:14400'],
            'content' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'يرجى كتابة نص الملاحظة.',
            'content.max' => 'نص الملاحظة يجب ألا يتجاوز 2000 حرف.',
            'timestamp_seconds.integer' => 'توقيت الملاحظة غير صالح.',
        ];
    }
}
