<?php

namespace App\Http\Requests\V1\Auth;

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
            'name'     => ['required', 'string', 'min:3', 'max:100', 'regex:/^[\p{Arabic}\p{L}\s\'.-]+$/u'],
            'email'    => ['required', 'string', 'email:rfc,filter', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', 'max:128', Password::defaults()],
            // SECURITY: admin can never be self-assigned
            'role'     => ['nullable', 'string', Rule::in(config('auth_security.self_registration_roles'))],
            'phone'    => ['nullable', 'string', 'regex:/^\+?[0-9]{8,15}$/', 'unique:users,phone'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->filled('email')) {
            $this->merge(['email' => strtolower((string) $this->input('email'))]);
        }
    }

    /**
     * Custom messages in Arabic.
     */
    public function messages(): array
    {
        return [
            'name.required'      => 'حقل الاسم مطلوب.',
            'name.min'           => 'يجب ألا يقل الاسم عن 3 أحرف.',
            'name.regex'         => 'الاسم يجب أن يحتوي على حروف فقط.',
            'email.required'     => 'حقل البريد الإلكتروني مطلوب.',
            'email.email'        => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique'       => 'هذا البريد الإلكتروني مستخدم بالفعل.',
            'password.required'  => 'حقل كلمة المرور مطلوب.',
            'password.confirmed' => 'تأكيد كلمة المرور غير متطابق.',
            'password.max'       => 'كلمة المرور طويلة جداً.',
            'role.in'            => 'الدور المختار غير متاح للتسجيل.',
            'phone.regex'        => 'رقم الهاتف غير صالح.',
            'phone.unique'       => 'رقم الهاتف مسجل مسبقاً.',
        ];
    }
}
