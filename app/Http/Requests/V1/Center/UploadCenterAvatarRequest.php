<?php

namespace App\Http\Requests\V1\Center;

use App\Http\Requests\BaseApiRequest;

class UploadCenterAvatarRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isCenter();
    }

    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:5120', // Max 5MB
            ],
        ];
    }
}
