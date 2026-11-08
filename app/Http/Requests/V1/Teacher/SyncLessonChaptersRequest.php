<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;

class SyncLessonChaptersRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    public function rules(): array
    {
        return [
            'chapters'                 => ['required', 'array', 'max:100'],
            'chapters.*.title'         => ['nullable', 'string', 'max:220'],
            'chapters.*.titleAr'       => ['nullable', 'string', 'max:220'],
            'chapters.*.title_ar'      => ['nullable', 'string', 'max:220'],
            'chapters.*.titleEn'       => ['nullable', 'string', 'max:220'],
            'chapters.*.title_en'      => ['nullable', 'string', 'max:220'],
            'chapters.*.startSeconds'  => ['nullable', 'integer', 'min:0', 'max:86400'],
            'chapters.*.start_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'chapters.*.timestamp'     => ['nullable', 'string', 'max:20'],
        ];
    }
}

