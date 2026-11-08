<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\PayoutAccountType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreTeacherPayoutAccountRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }
    protected function prepareForValidation(): void
    {
        $identifier = $this->input('account_identifier')
            ?? $this->input('value')
            ?? $this->input('address')
            ?? $this->input('phone')
            ?? $this->input('accountNumber')
            ?? $this->input('account_number');

        if ($identifier !== null) {
            $this->merge([
                'account_identifier' => trim(strip_tags((string) $identifier)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'type'               => ['required', 'string', Rule::in(PayoutAccountType::values())],
            'account_identifier' => ['required', 'string', 'min:4', 'max:120'],
            'value'              => ['nullable', 'string', 'max:120'],
            'address'            => ['nullable', 'string', 'max:120'],
            'phone'              => ['nullable', 'string', 'max:40'],
            'accountNumber'      => ['nullable', 'string', 'max:120'],
            'account_number'     => ['nullable', 'string', 'max:120'],
            'label'              => ['nullable', 'string', 'max:120'],
            'labelAr'            => ['nullable', 'string', 'max:120'],
            'label_ar'           => ['nullable', 'string', 'max:120'],
            'labelEn'            => ['nullable', 'string', 'max:120'],
            'label_en'           => ['nullable', 'string', 'max:120'],
            'isDefault'          => ['nullable', 'boolean'],
            'is_default'         => ['nullable', 'boolean'],
            'isActive'           => ['nullable', 'boolean'],
            'is_active'          => ['nullable', 'boolean'],
        ];
    }
}
