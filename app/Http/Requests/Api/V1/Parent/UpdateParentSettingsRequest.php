<?php

namespace App\Http\Requests\Api\V1\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isParent() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('name')) {
            $payload['name'] = trim(strip_tags((string) $this->input('name')));
        }
        if ($this->has('phone')) {
            $payload['phone'] = trim((string) $this->input('phone'));
        }
        if ($this->has('email')) {
            $payload['email'] = strtolower(trim((string) $this->input('email')));
        }
        if ($this->has('emergency_phone') || $this->has('emergencyPhone')) {
            $raw = $this->input('emergency_phone', $this->input('emergencyPhone'));
            $payload['emergency_phone'] = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
        }
        if ($this->has('notify_tuition_renewal') || $this->has('notifyTuitionRenewal')) {
            $payload['notify_tuition_renewal'] = filter_var(
                $this->input('notify_tuition_renewal', $this->input('notifyTuitionRenewal')),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }
        if ($this->has('notify_achievements_certificates') || $this->has('notifyAchievementsCertificates')) {
            $payload['notify_achievements_certificates'] = filter_var(
                $this->input('notify_achievements_certificates', $this->input('notifyAchievementsCertificates')),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }
        if ($this->has('preferred_lang') || $this->has('preferredLang')) {
            $payload['preferred_lang'] = strtolower(trim((string) $this->input('preferred_lang', $this->input('preferredLang'))));
        }
        if ($this->has('preferred_theme') || $this->has('preferredTheme')) {
            $payload['preferred_theme'] = strtolower(trim((string) $this->input('preferred_theme', $this->input('preferredTheme'))));
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:150',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'emergency_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notify_tuition_renewal' => ['sometimes', 'boolean'],
            'notify_achievements_certificates' => ['sometimes', 'boolean'],
            'preferred_lang' => ['sometimes', 'string', 'in:ar,en'],
            'preferred_theme' => ['sometimes', 'string', 'in:light,dark,system'],
        ];
    }
}
