<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\LessonMediaMode;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UploadLessonMediaRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    public function rules(): array
    {
        return [
            'media'            => ['required', 'file', 'max:512000'], // up to 500MB in API request
            'mode'             => ['nullable', 'string', Rule::in(LessonMediaMode::values())],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'durationSeconds'  => ['nullable', 'integer', 'min:1', 'max:86400'],
        ];
    }
}

