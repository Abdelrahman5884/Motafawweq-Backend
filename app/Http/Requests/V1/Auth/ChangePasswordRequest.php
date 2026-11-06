<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:128'],
            'new_password'     => [
                'required',
                'string',
                'max:128',
                'confirmed',
                'different:current_password',
                Password::defaults(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'كلمة المرور الحالية مطلوبة.',
            'new_password.required'     => 'كلمة المرور الجديدة مطلوبة.',
            'new_password.confirmed'    => 'تأكيد كلمة المرور الجديدة غير متطابق.',
            'new_password.different'    => 'يجب أن تكون كلمة المرور الجديدة مختلفة عن الحالية.',
        ];
    }
}
