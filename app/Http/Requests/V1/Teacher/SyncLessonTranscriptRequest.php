<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;

class SyncLessonTranscriptRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $segments = $this->input('segments') ?? $this->input('transcript');
        if (is_array($segments)) {
            $this->merge(['segments' => $segments]);
        }
    }

    public function rules(): array
    {
        return [
            'segments'                 => ['required', 'array', 'max:500'],
            'segments.*.speaker'       => ['nullable', 'string', 'max:120'],
            'segments.*.startSeconds'  => ['nullable', 'integer', 'min:0', 'max:86400'],
            'segments.*.start_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'segments.*.timestamp'     => ['nullable', 'string', 'max:20'],
            'segments.*.text'          => ['nullable', 'string', 'max:5000'],
            'segments.*.textAr'        => ['nullable', 'string', 'max:5000'],
            'segments.*.text_ar'       => ['nullable', 'string', 'max:5000'],
            'segments.*.textEn'        => ['nullable', 'string', 'max:5000'],
            'segments.*.text_en'       => ['nullable', 'string', 'max:5000'],
        ];
    }
}

