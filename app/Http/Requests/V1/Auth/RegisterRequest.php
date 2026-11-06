<?php

namespace App\Http\Requests\V1\Auth;

use App\Enums\UserRole;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'min:3', 'max:100'],
            'email'    => ['required', 'string', 'email:rfc,filter', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
            ],
            'role'     => ['nullable', 'string', Rule::in(UserRole::values())],
            'phone'    => ['nullable', 'string', 'max:20', 'unique:users,phone'],
        ];
    }

    /**
     * Custom messages in Arabic.
     */
    public function messages(): array
    {
        return [
            'name.required'     => 'حقل الاسم مطلوب.',
            'name.min'          => 'يجب ألا يقل الاسم عن 3 أحرف.',
            'email.required'    => 'حقل البريد الإلكتروني مطلوب.',
            'email.email'       => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique'      => 'هذا البريد الإلكتروني مستخدم بالفعل.',
            'password.required' => 'حقل كلمة المرور مطلوب.',
            'password.confirmed'=> 'تأكيد كلمة المرور غير متطابق.',
            'role.in'           => 'الدور المختار غير متاح في النظام.',
            'phone.unique'      => 'رقم الهاتف مسجل مسبقاً.',
        ];
    }
}
