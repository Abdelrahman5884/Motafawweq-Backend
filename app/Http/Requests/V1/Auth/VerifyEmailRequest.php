<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseApiRequest;

class VerifyEmailRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:' . config('auth_security.otp.length')],
        ];
    }
}
