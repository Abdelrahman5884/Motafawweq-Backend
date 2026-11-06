<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseApiRequest;

class LoginRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ];
    }
}
