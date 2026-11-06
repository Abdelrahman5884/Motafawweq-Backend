<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:255'],
            'code'     => ['required', 'string', 'digits:' . config('auth_security.otp.length')],
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::defaults()],
        ];
    }
}
