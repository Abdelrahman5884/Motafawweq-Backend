<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $merged = [];
        if ($this->has('role')) {
            $rawRole = strtolower(trim((string) $this->input('role')));
            $merged['role'] = $rawRole === 'center_admin' ? 'center' : $rawRole;
        }
        if ($this->has('email')) {
            $merged['email'] = strtolower(trim((string) $this->input('email')));
        }
        if ($this->has('nationalId') && ! $this->has('national_id')) {
            $merged['national_id'] = $this->input('nationalId');
        }
        if (($this->has('details') || $this->has('detailsAr')) && ! $this->has('details_ar')) {
            $merged['details_ar'] = $this->input('detailsAr', $this->input('details'));
        }
        if ($this->has('detailsEn') && ! $this->has('details_en')) {
            $merged['details_en'] = $this->input('detailsEn');
        }
        if ($this->has('degreeAr') && ! $this->has('degree_ar')) {
            $merged['degree_ar'] = $this->input('degreeAr');
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    /**
     * SECURITY: Promoting any account to 'admin' is strictly forbidden.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $targetId = (int) $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:190',
                Rule::unique('users', 'email')->ignore($targetId),
            ],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]{7,20}$/',
                Rule::unique('users', 'phone')->ignore($targetId),
            ],
            'role' => ['sometimes', 'string', Rule::in(['student', 'teacher', 'parent', 'center'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'pending', 'pending_verification', 'verified', 'suspended'])],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-]+$/'],
            'details_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'details_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'degree_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
