<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\StageType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherSettingsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;
        $allowedGrades = StageType::allowedGradesAr();

        return [
            // Profile & Academic Identity (camelCase & snake_case supported)
            'nameAr'     => ['sometimes', 'required', 'string', 'min:3', 'max:150'],
            'name_ar'    => ['sometimes', 'required', 'string', 'min:3', 'max:150'],
            'nameEn'     => ['sometimes', 'nullable', 'string', 'max:150'],
            'name_en'    => ['sometimes', 'nullable', 'string', 'max:150'],
            'titleAr'    => ['sometimes', 'nullable', 'string', 'max:200'],
            'title_ar'   => ['sometimes', 'nullable', 'string', 'max:200'],
            'titleEn'    => ['sometimes', 'nullable', 'string', 'max:200'],
            'title_en'   => ['sometimes', 'nullable', 'string', 'max:200'],
            'subjectAr'  => ['sometimes', 'nullable', 'string', 'max:120'],
            'subject_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'subjectEn'  => ['sometimes', 'nullable', 'string', 'max:120'],
            'subject_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bioAr'      => ['sometimes', 'nullable', 'string', 'max:3000'],
            'bio_ar'     => ['sometimes', 'nullable', 'string', 'max:3000'],
            'bioEn'      => ['sometimes', 'nullable', 'string', 'max:3000'],
            'bio_en'     => ['sometimes', 'nullable', 'string', 'max:3000'],
            'email'      => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone'          => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'whatsapp_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'avatar'         => ['sometimes', 'nullable', 'string', 'max:3000000'], // URL or base64 data URI up to ~2MB

            // Stages Taught
            'stagesTaught'     => ['sometimes', 'array', 'max:9'],
            'stagesTaught.*'   => ['string', Rule::in($allowedGrades)],
            'stages_taught'    => ['sometimes', 'array', 'max:9'],
            'stages_taught.*'  => ['string', Rule::in($allowedGrades)],

            // Preferences & Security Toggles
            'twoFactorEnabled'           => ['sometimes', 'boolean'],
            'two_factor_enabled'         => ['sometimes', 'boolean'],
            'notifyWhatsappIntervention' => ['sometimes', 'boolean'],
            'notify_whatsapp_intervention' => ['sometimes', 'boolean'],
            'notifyNewEnrollment'        => ['sometimes', 'boolean'],
            'notify_new_enrollment'      => ['sometimes', 'boolean'],
            'notifyExamSubmission'       => ['sometimes', 'boolean'],
            'notify_exam_submission'     => ['sometimes', 'boolean'],
            'autoWatermarkEnabled'       => ['sometimes', 'boolean'],
            'auto_watermark_enabled'     => ['sometimes', 'boolean'],
            'preferredTheme'             => ['sometimes', 'string', Rule::in(['light', 'dark'])],
            'preferred_theme'            => ['sometimes', 'string', Rule::in(['light', 'dark'])],
            'preferredLang'              => ['sometimes', 'string', Rule::in(['ar', 'en'])],
            'preferred_lang'             => ['sometimes', 'string', Rule::in(['ar', 'en'])],
        ];
    }
}

