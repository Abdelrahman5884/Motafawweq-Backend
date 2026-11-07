<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseApiRequest;

class VerifyResetOtpRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code'  => ['required', 'string', 'digits:' . config('auth_security.otp.length')],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->filled('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }
}

