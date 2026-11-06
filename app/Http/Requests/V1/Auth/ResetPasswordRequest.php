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

    public function messages(): array
    {
        return [
            'email.required'     => 'حقل البريد الإلكتروني مطلوب.',
            'code.required'      => 'رمز التحقق مطلوب.',
            'code.digits'        => 'رمز التحقق غير صالح.',
            'password.required'  => 'كلمة المرور الجديدة مطلوبة.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
        ];
    }
}

