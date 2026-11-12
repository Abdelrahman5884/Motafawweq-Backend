<?php

namespace App\Http\Requests\V1\Storage;

use App\Http\Requests\BaseApiRequest;

class PresignUploadRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'entity_type'        => ['required', 'string', 'in:user_avatar,center_logo,center_document,course_thumbnail,course_resource,lesson_media,lesson_attachment,lesson_transcript,lesson_mindmap,lesson_quiz,student_submission'],
            'entity_id'          => ['nullable', 'string', 'max:100'],
            'filename'           => ['required', 'string', 'max:255'],
            'mime_type'          => ['required', 'string', 'max:100'],
            'file_size'          => ['required', 'integer', 'min:1', 'max:52428800'], // Max 50 MB
            'metadata'           => ['nullable', 'array'],
            'metadata.title'     => ['nullable', 'string', 'max:255'],
            'metadata.caption'   => ['nullable', 'string', 'max:500'],
            'metadata.duration'  => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'file_size.max' => 'حجم الملف يتجاوز الحد الأقصى المسموح به (50 ميجابايت) في منصة متفوق.',
            'mime_type.required' => 'نوع الملف (MIME Type) مطلوب.',
            'filename.required' => 'اسم الملف مطلوب.',
            'entity_type.required' => 'نوع الكيان مطلوب.',
        ];
    }
}

