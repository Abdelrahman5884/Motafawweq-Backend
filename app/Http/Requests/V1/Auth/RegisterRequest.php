<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'min:3', 'max:100', 'regex:/^[\p{Arabic}\p{L}\s\'.-]+$/u'],
            'email'        => ['required', 'string', 'email:rfc,filter', 'max:255', 'unique:users,email'],
            'password'     => ['required', 'string', 'confirmed', 'max:128', Password::defaults()],
            // SECURITY: admin can never be self-assigned
            'role'         => ['nullable', 'string', Rule::in(config('auth_security.self_registration_roles'))],
            'phone'        => ['nullable', 'string', 'regex:/^\+?[0-9]{8,15}$/', 'unique:users,phone'],
            'student_code' => ['required_if:role,parent', 'nullable', 'string', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->filled('email')) {
            $this->merge(['email' => strtolower((string) $this->input('email'))]);
        }

        if ($this->filled('student_code')) {
            $this->merge(['student_code' => strtoupper(trim((string) $this->input('student_code')))]);
        }
    }
}
