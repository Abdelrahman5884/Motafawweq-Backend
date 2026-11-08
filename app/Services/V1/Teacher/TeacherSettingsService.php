<?php

namespace App\Services\V1\Teacher;

use App\Enums\StageType;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Repositories\Contracts\TeacherPayoutAccountRepositoryInterface;
use App\Repositories\Contracts\TeacherProfileRepositoryInterface;
use App\Services\BaseService;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherSettingsService extends BaseService
{
    public function __construct(
        protected TeacherProfileRepositoryInterface $profileRepository,
        protected TeacherPayoutAccountRepositoryInterface $payoutRepository
    ) {}

    /**
     * Retrieve (or initialize) the authenticated teacher's profile & settings.
     */
    public function getSettings(User $teacher): TeacherProfile
    {
        $profile = $this->profileRepository->firstOrCreateForTeacher($teacher);
        $this->payoutRepository->seedDefaultsIfEmpty($teacher);

        return $profile->load(['user.teacherPayoutAccounts']);
    }

    /**
     * Update full or partial teacher settings (profile, stages, preferences, security toggles).
     * Accepts both camelCase (frontend state) and snake_case keys.
     */
    public function updateSettings(User $teacher, array $data): TeacherProfile
    {
        return $this->transaction(function () use ($teacher, $data) {
            $profile = $this->profileRepository->firstOrCreateForTeacher($teacher);

            $profileUpdates = [];
            $userUpdates = [];

            // 1. Personal & Academic Identity
            if (array_key_exists('nameAr', $data) || array_key_exists('name_ar', $data)) {
                $nameAr = trim((string) ($data['nameAr'] ?? $data['name_ar']));
                $profileUpdates['name_ar'] = $nameAr;
                $userUpdates['name'] = $nameAr;
            }

            if (array_key_exists('nameEn', $data) || array_key_exists('name_en', $data)) {
                $profileUpdates['name_en'] = trim((string) ($data['nameEn'] ?? $data['name_en']));
            }

            if (array_key_exists('titleAr', $data) || array_key_exists('title_ar', $data)) {
                $profileUpdates['title_ar'] = trim((string) ($data['titleAr'] ?? $data['title_ar']));
            }

            if (array_key_exists('titleEn', $data) || array_key_exists('title_en', $data)) {
                $profileUpdates['title_en'] = trim((string) ($data['titleEn'] ?? $data['title_en']));
            }

            if (array_key_exists('subjectAr', $data) || array_key_exists('subject_ar', $data)) {
                $profileUpdates['subject_ar'] = trim((string) ($data['subjectAr'] ?? $data['subject_ar']));
            }

            if (array_key_exists('subjectEn', $data) || array_key_exists('subject_en', $data)) {
                $profileUpdates['subject_en'] = trim((string) ($data['subjectEn'] ?? $data['subject_en']));
            }

            if (array_key_exists('bioAr', $data) || array_key_exists('bio_ar', $data)) {
                $profileUpdates['bio_ar'] = trim((string) ($data['bioAr'] ?? $data['bio_ar']));
            }

            if (array_key_exists('bioEn', $data) || array_key_exists('bio_en', $data)) {
                $profileUpdates['bio_en'] = trim((string) ($data['bioEn'] ?? $data['bio_en']));
            }

            if (array_key_exists('phone', $data) || array_key_exists('whatsapp_phone', $data)) {
                $phone = trim((string) ($data['phone'] ?? $data['whatsapp_phone']));
                $profileUpdates['whatsapp_phone'] = $phone;
                $userUpdates['phone'] = $phone;
            }

            if (array_key_exists('email', $data)) {
                $email = strtolower(trim((string) $data['email']));
                if ($email !== '' && $email !== $teacher->email) {
                    $userUpdates['email'] = $email;
                }
            }

            if (array_key_exists('avatar', $data) && is_string($data['avatar']) && $data['avatar'] !== '') {
                $userUpdates['avatar'] = $this->processInlineAvatarIfNeeded($teacher, $data['avatar']);
            }

            // 2. Stages & Curriculum
            if (array_key_exists('stagesTaught', $data) || array_key_exists('stages_taught', $data)) {
                $rawStages = (array) ($data['stagesTaught'] ?? $data['stages_taught'] ?? []);
                $allowed = StageType::allowedGradesAr();
                $profileUpdates['stages_taught'] = array_values(array_unique(array_intersect($rawStages, $allowed)));
            }

            // 3. Preferences & Notifications & Security Toggles
            $booleanMappings = [
                'two_factor_enabled'           => ['twoFactorEnabled', 'two_factor_enabled'],
                'notify_whatsapp_intervention' => ['notifyWhatsappIntervention', 'notify_whatsapp_intervention'],
                'notify_new_enrollment'        => ['notifyNewEnrollment', 'notify_new_enrollment'],
                'notify_exam_submission'       => ['notifyExamSubmission', 'notify_exam_submission'],
                'auto_watermark_enabled'       => ['autoWatermarkEnabled', 'auto_watermark_enabled'],
            ];

            foreach ($booleanMappings as $column => [$camelKey, $snakeKey]) {
                if (array_key_exists($camelKey, $data)) {
                    $profileUpdates[$column] = (bool) $data[$camelKey];
                } elseif (array_key_exists($snakeKey, $data)) {
                    $profileUpdates[$column] = (bool) $data[$snakeKey];
                }
            }

            if (array_key_exists('preferredTheme', $data) || array_key_exists('preferred_theme', $data)) {
                $theme = strtolower((string) ($data['preferredTheme'] ?? $data['preferred_theme']));
                if (in_array($theme, ['light', 'dark'], true)) {
                    $profileUpdates['preferred_theme'] = $theme;
                }
            }

            if (array_key_exists('preferredLang', $data) || array_key_exists('preferred_lang', $data)) {
                $lang = strtolower((string) ($data['preferredLang'] ?? $data['preferred_lang']));
                if (in_array($lang, ['ar', 'en'], true)) {
                    $profileUpdates['preferred_lang'] = $lang;
                }
            }

            if (!empty($userUpdates)) {
                $teacher->update($userUpdates);
            }

            if (!empty($profileUpdates)) {
                $profile->update($profileUpdates);
            }

            $this->logSecurityEvent(
                'teacher.settings.updated',
                [
                    'updated_profile_fields' => array_keys($profileUpdates),
                    'updated_user_fields'    => array_keys($userUpdates),
                ],
                'info',
                $profile
            );

            return $profile->fresh(['user']);
        });
    }

    /**
     * Update only the curriculum stages taught by the teacher.
     */
    public function updateStages(User $teacher, array $stagesTaught): TeacherProfile
    {
        return $this->updateSettings($teacher, ['stages_taught' => $stagesTaught]);
    }

    /**
     * Update only interface & alert preferences.
     */
    public function updatePreferences(User $teacher, array $preferences): TeacherProfile
    {
        return $this->updateSettings($teacher, $preferences);
    }

    /**
     * Securely upload and replace the teacher's avatar image.
     */
    public function uploadAvatar(User $teacher, UploadedFile $file): TeacherProfile
    {
        // Strict Magic-Bytes MIME Verification (defense against spoofed file extensions)
        $realPath = $file->getRealPath();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $realPath ? $finfo->file($realPath) : $file->getMimeType();

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimes[$detectedMime])) {
            throw ValidationException::withMessages([
                'avatar' => [__('messages.invalid_image_signature')],
            ]);
        }

        return $this->transaction(function () use ($teacher, $file, $allowedMimes, $detectedMime) {
            $profile = $this->profileRepository->firstOrCreateForTeacher($teacher);

            $extension = $allowedMimes[$detectedMime];
            $filename = 'avatars/teachers/' . Str::uuid() . '.' . $extension;

            Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));

            // Clean up previous local avatar if it was stored on public disk
            $this->deleteOldLocalAvatar($teacher->avatar);

            $publicUrl = Storage::disk('public')->url($filename);
            $teacher->update(['avatar' => $publicUrl]);

            $this->logSecurityEvent(
                'teacher.avatar.uploaded',
                [
                    'mime_type' => $detectedMime,
                    'size'      => $file->getSize(),
                    'path'      => $filename,
                ],
                'info',
                $profile
            );

            return $profile->fresh(['user']);
        });
    }

    /**
     * Handle inline avatar value (either an HTTPS URL or a safe base64 DataURL from FileReader).
     */
    protected function processInlineAvatarIfNeeded(User $teacher, string $avatarValue): string
    {
        if (str_starts_with($avatarValue, 'data:image/')) {
            if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,([a-zA-Z0-9+\/=]+)$/', $avatarValue, $matches)) {
                throw ValidationException::withMessages([
                    'avatar' => [__('messages.invalid_image_signature')],
                ]);
            }

            $binary = base64_decode($matches[2], true);
            if ($binary === false || strlen($binary) > 2 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'avatar' => [__('messages.invalid_image_signature')],
                ]);
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->buffer($binary);
            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowedMimes[$detectedMime])) {
                throw ValidationException::withMessages([
                    'avatar' => [__('messages.invalid_image_signature')],
                ]);
            }

            $filename = 'avatars/teachers/' . Str::uuid() . '.' . $allowedMimes[$detectedMime];
            Storage::disk('public')->put($filename, $binary);

            $this->deleteOldLocalAvatar($teacher->avatar);

            return Storage::disk('public')->url($filename);
        }

        return $avatarValue;
    }

    protected function deleteOldLocalAvatar(?string $oldAvatarUrl): void
    {
        if (!$oldAvatarUrl) {
            return;
        }

        if (preg_match('#/storage/(avatars/teachers/[a-zA-Z0-9\-]+\.(jpg|png|webp))$#', $oldAvatarUrl, $matches)) {
            Storage::disk('public')->delete($matches[1]);
        }
    }
}

