<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\StageType;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class TeacherSettingsResource extends BaseJsonResource
{
    /**
     * Transform the teacher profile & settings resource into an array matching TeacherSettingsView.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $payoutAccounts = $user?->teacherPayoutAccounts ?? collect();
        $defaultAccount = $payoutAccounts->firstWhere('is_default', true) ?? $payoutAccounts->first();

        return [
            'id'                         => $this->uuid,
            'userId'                     => $this->user_id,

            // Profile & Academic Identity
            'nameAr'                     => $this->name_ar,
            'nameEn'                     => $this->name_en ?? $this->name_ar,
            'titleAr'                    => $this->title_ar,
            'titleEn'                    => $this->title_en,
            'subjectAr'                  => $this->subject_ar,
            'subjectEn'                  => $this->subject_en,
            'email'                      => $user?->email,
            'phone'                      => $this->whatsapp_phone ?? $user?->phone,
            'bioAr'                      => $this->bio_ar,
            'bioEn'                      => $this->bio_en,
            'avatar'                     => $user?->avatar ?? 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=200&auto=format&fit=crop&q=80',
            'isVerifiedEducator'         => (bool) $this->is_verified_educator,
            'planTier'                   => $this->plan_tier?->value ?? 'plus',
            'planLabel'                  => $this->plan_tier?->label(),
            'rating'                     => $this->rating,
            'reviewsCount'               => $this->reviews_count,

            // Stages & Curriculum
            'stagesTaught'               => $this->stages_taught ?? [],
            'stagesCatalog'              => StageType::catalog(),

            // Direct Student Receiving Accounts (Encrypted at Rest)
            'defaultPayout'              => $defaultAccount?->uuid,
            'payoutMethods'              => TeacherPayoutAccountResource::collection($payoutAccounts)->resolve($request),

            // Security & Preferences
            'twoFactorEnabled'           => (bool) $this->two_factor_enabled,
            'notifyWhatsappIntervention' => (bool) $this->notify_whatsapp_intervention,
            'notifyNewEnrollment'        => (bool) $this->notify_new_enrollment,
            'notifyExamSubmission'       => (bool) $this->notify_exam_submission,
            'autoWatermarkEnabled'       => (bool) $this->auto_watermark_enabled,
            'preferredTheme'             => $this->preferred_theme ?? 'light',
            'preferredLang'              => $this->preferred_lang ?? 'ar',

            'updatedAt'                  => $this->formatDate($this->updated_at),
        ];
    }
}

