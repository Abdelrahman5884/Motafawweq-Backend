<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherPreferencesRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    public function rules(): array
    {
        return [
            'twoFactorEnabled'             => ['sometimes', 'boolean'],
            'two_factor_enabled'           => ['sometimes', 'boolean'],
            'notifyWhatsappIntervention'   => ['sometimes', 'boolean'],
            'notify_whatsapp_intervention' => ['sometimes', 'boolean'],
            'notifyNewEnrollment'          => ['sometimes', 'boolean'],
            'notify_new_enrollment'        => ['sometimes', 'boolean'],
            'notifyExamSubmission'         => ['sometimes', 'boolean'],
            'notify_exam_submission'       => ['sometimes', 'boolean'],
            'autoWatermarkEnabled'         => ['sometimes', 'boolean'],
            'auto_watermark_enabled'       => ['sometimes', 'boolean'],
            'preferredTheme'               => ['sometimes', 'string', Rule::in(['light', 'dark'])],
            'preferred_theme'              => ['sometimes', 'string', Rule::in(['light', 'dark'])],
            'preferredLang'                => ['sometimes', 'string', Rule::in(['ar', 'en'])],
            'preferred_lang'               => ['sometimes', 'string', Rule::in(['ar', 'en'])],
        ];
    }
}

