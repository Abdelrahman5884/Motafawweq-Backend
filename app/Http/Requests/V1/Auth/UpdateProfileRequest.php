<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name'   => ['sometimes', 'required', 'string', 'min:3', 'max:100'],
            'phone'  => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($userId)],
            'avatar' => ['nullable', 'string', 'max:255'],
        ];
    }
}
