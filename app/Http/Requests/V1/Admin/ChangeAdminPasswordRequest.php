<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rules\Password;

class ChangeAdminPasswordRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $revoke = null;
        if ($this->has('revoke_other_sessions')) {
            $revoke = $this->input('revoke_other_sessions');
        } elseif ($this->has('revokeOtherSessions')) {
            $revoke = $this->input('revokeOtherSessions');
        }

        $this->merge(array_filter([
            'current_password' => $this->input('current_password', $this->input('currentPassword')),
            'new_password' => $this->input('new_password', $this->input('newPassword', $this->input('password'))),
            'new_password_confirmation' => $this->input(
                'new_password_confirmation',
                $this->input('newPasswordConfirmation', $this->input('password_confirmation'))
            ),
            'revoke_other_sessions' => $revoke,
        ], static fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:128'],
            'new_password' => [
                'required',
                'string',
                'max:128',
                'different:current_password',
                'confirmed',
                Password::min(10)->mixedCase()->numbers()->symbols(),
            ],
            'revoke_other_sessions' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'يرجى إدخال كلمة المرور الحالية.',
            'new_password.required' => 'يرجى إدخال كلمة المرور الجديدة.',
            'new_password.different' => 'يجب أن تكون كلمة المرور الجديدة مختلفة عن الحالية.',
            'new_password.confirmed' => 'تأكيد كلمة المرور الجديدة غير متطابق.',
            'new_password.min' => 'يجب ألا تقل كلمة المرور الجديدة عن 10 أحرف وتحتوي على أحرف كبيرة وصغيرة وأرقام ورموز.',
        ];
    }
}
