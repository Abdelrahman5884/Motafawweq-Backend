<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAdminUserRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $rawRole = strtolower(trim((string) $this->input('role', 'student')));
        if ($rawRole === 'center_admin') {
            $rawRole = 'center';
        }

        $this->merge(array_filter([
            'role' => $rawRole,
            'email' => $this->has('email') ? strtolower(trim((string) $this->input('email'))) : null,
            'national_id' => $this->input('national_id', $this->input('nationalId')),
            'details_ar' => $this->input('details_ar', $this->input('detailsAr', $this->input('details'))),
            'details_en' => $this->input('details_en', $this->input('detailsEn')),
            'degree_ar' => $this->input('degree_ar', $this->input('degreeAr')),
            'grade_ar' => $this->input('grade_ar', $this->input('grade')),
            'track_ar' => $this->input('track_ar', $this->input('track')),
            'parent_phone' => $this->input('parent_phone', $this->input('parentPhone')),
        ], fn ($v) => $v !== null));
    }

    /**
     * SECURITY: 'admin' role is intentionally NEVER allowed to be provisioned via this API.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/', 'unique:users,phone'],
            'role' => ['required', 'string', Rule::in(['student', 'teacher', 'parent', 'center'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'pending', 'pending_verification', 'verified', 'suspended'])],
            'password' => ['nullable', 'string', Password::defaults()],
            'national_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-]+$/'],
            'details_ar' => ['nullable', 'string', 'max:255'],
            'details_en' => ['nullable', 'string', 'max:255'],
            'degree_ar' => ['nullable', 'string', 'max:255'],
            'grade_ar' => ['nullable', 'string', 'max:120'],
            'track_ar' => ['nullable', 'string', 'max:120'],
            'governorate' => ['nullable', 'string', 'max:100'],
            'parent_phone' => ['nullable', 'string', 'max:25'],
            'subject_ar' => ['nullable', 'string', 'max:120'],
        ];
    }
}
