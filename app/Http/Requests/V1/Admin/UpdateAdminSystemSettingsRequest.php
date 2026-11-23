<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateAdminSystemSettingsRequest extends BaseApiRequest
{
    /**
     * Strictly whitelisted camelCase -> snake_case setting keys.
     *
     * @var array<string, string>
     */
    public const ALIAS_MAP = [
        'maintenanceMode' => 'maintenance_mode',
        'allowTeacherRegistration' => 'allow_teacher_registration',
        'autoCertificates' => 'auto_certificates',
        'antiPassback' => 'anti_passback',
        'antiPassbackMinutes' => 'anti_passback_minutes',
        'highPerformanceAudio' => 'high_performance_audio',
        'logLevel' => 'log_level',
        'platformCommissionPercent' => 'platform_commission_percent',
        'aiCostPerMinuteEgp' => 'ai_cost_per_minute_egp',
        'aiRevenuePerMinuteEgp' => 'ai_revenue_per_minute_egp',
    ];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];
        foreach (self::ALIAS_MAP as $camel => $snake) {
            if ($this->has($camel) && ! $this->has($snake)) {
                $normalized[$snake] = $this->input($camel);
            }
        }

        if (! empty($normalized)) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'maintenance_mode' => ['sometimes', 'boolean'],
            'allow_teacher_registration' => ['sometimes', 'boolean'],
            'auto_certificates' => ['sometimes', 'boolean'],
            'anti_passback' => ['sometimes', 'boolean'],
            'anti_passback_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'high_performance_audio' => ['sometimes', 'boolean'],
            'log_level' => ['sometimes', 'string', Rule::in(['standard', 'verbose', 'strict'])],
            'platform_commission_percent' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'ai_cost_per_minute_egp' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'ai_revenue_per_minute_egp' => ['sometimes', 'numeric', 'min:0', 'max:500'],
        ];
    }

    /**
     * Reject unknown/unrecognized setting keys to enforce strict allow-listing.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $allowed = array_merge(array_keys(self::ALIAS_MAP), array_values(self::ALIAS_MAP));
            $unknown = array_diff(array_keys($this->all()), $allowed);

            if (! empty($unknown)) {
                $v->errors()->add('settings', __('messages.admin.unknown_setting_keys', [
                    'keys' => implode(', ', $unknown),
                ]));
            }
        });
    }
}
