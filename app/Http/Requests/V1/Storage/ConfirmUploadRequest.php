<?php

namespace App\Http\Requests\V1\Storage;

use App\Http\Requests\BaseApiRequest;

class ConfirmUploadRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'file_id' => ['required', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'file_id.required' => 'معرف الملف المرفوع مطلوب.',
            'file_id.uuid'     => 'معرف الملف غير صالح.',
        ];
    }
}

