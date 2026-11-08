<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\LessonAttachmentType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UploadLessonAttachmentRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    public function rules(): array
    {
        return [
            'file'        => ['required', 'file', 'max:51200'], // up to 50MB per PDF/image
            'type'        => ['nullable', 'string', Rule::in(LessonAttachmentType::values())],
            'title'       => ['nullable', 'string', 'max:220'],
            'caption'     => ['nullable', 'string', 'max:500'],
            'pages_count' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'pagesCount'  => ['nullable', 'integer', 'min:1', 'max:2000'],
        ];
    }
}

