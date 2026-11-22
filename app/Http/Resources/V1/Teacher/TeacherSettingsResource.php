<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\StageType;
use App\Enums\TeacherPlanTier;
use App\Http\Resources\BaseJsonResource;
use App\Services\V1\Teacher\TeacherBillingService;
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
        $resolvedTier = $user
            ? app(TeacherBillingService::class)->resolveActivePlanTier($user)
            : ($this->plan_tier ?? TeacherPlanTier::FREE);
        $allowsFinancials = $resolvedTier->allowsFinancials();
        $payoutAccounts = $allowsFinancials ? ($user?->teacherPayoutAccounts ?? collect()) : collect();
        $defaultAccount = $payoutAccounts->firstWhere('is_default', true) ?? $payoutAccounts->first();
        $avatar = $user?->avatar ?: null;

        return [
            'id' => $this->uuid,
            'userId' => $this->user_id,

            // Profile & Academic Identity
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en ?? $this->name_ar,
            'titleAr' => $this->title_ar,
            'titleEn' => $this->title_en,
            'subjectAr' => $this->subject_ar,
            'subjectEn' => $this->subject_en,
            'email' => $user?->email,
            'emailVerified' => $user?->email_verified_at !== null,
            'phone' => $this->whatsapp_phone ?? $user?->phone,
            'bioAr' => $this->bio_ar,
            'bioEn' => $this->bio_en,
            'avatar' => $avatar,
            'avatarUrl' => $avatar,
            'avatar_url' => $avatar,
            'isVerifiedEducator' => (bool) $this->is_verified_educator,
            'planTier' => $resolvedTier->value,
            'planLabel' => $resolvedTier->label(),
            'planQuotas' => $resolvedTier->quotas(),
            'unlockedFeatures' => $resolvedTier->unlockedFeatures(),
            'rating' => $this->rating,
            'reviewsCount' => $this->reviews_count,

            // Stages & Curriculum
            'stagesTaught' => $this->stages_taught ?? [],
            'stagesCatalog' => StageType::catalog(),

            // Direct Student Receiving Accounts (Encrypted at Rest — Plus & Pro)
            'defaultPayout' => $defaultAccount?->uuid,
            'payoutMethods' => TeacherPayoutAccountResource::collection($payoutAccounts)->resolve($request),

            // Preferences
            'twoFactorEnabled' => (bool) $this->two_factor_enabled,
            'notifyWhatsappIntervention' => (bool) $this->notify_whatsapp_intervention,
            'notifyNewEnrollment' => (bool) $this->notify_new_enrollment,
            'notifyExamSubmission' => (bool) $this->notify_exam_submission,
            'autoWatermarkEnabled' => (bool) $this->auto_watermark_enabled,
            'preferredTheme' => $this->preferred_theme ?? 'light',
            'preferredLang' => $this->preferred_lang ?? 'ar',

            'updatedAt' => $this->formatDate($this->updated_at),
        ];
    }
}
