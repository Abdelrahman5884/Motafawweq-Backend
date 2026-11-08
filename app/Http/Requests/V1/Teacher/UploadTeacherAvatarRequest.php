<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;

class UploadTeacherAvatarRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:2048', // Max 2MB
            ],
        ];
    }
}
