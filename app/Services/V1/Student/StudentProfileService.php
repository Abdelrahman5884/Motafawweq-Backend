<?php

namespace App\Services\V1\Student;

use App\Enums\StageType;
use App\Models\StoredFile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\BaseService;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudentProfileService extends BaseService
{
    /**
     * Map legacy English grade labels to canonical Arabic grades used across the platform.
     *
     * @var array<string, string>
     */
    private const LEGACY_GRADE_MAP = [
        '1st Primary' => 'الصف الأول الابتدائي',
        '2nd Primary' => 'الصف الثاني الابتدائي',
        '3rd Primary' => 'الصف الثالث الابتدائي',
        '4th Primary' => 'الصف الرابع الابتدائي',
        '5th Primary' => 'الصف الخامس الابتدائي',
        '6th Primary' => 'الصف السادس الابتدائي',
        '1st Preparatory' => 'الصف الأول الإعدادي',
        '2nd Preparatory' => 'الصف الثاني الإعدادي',
        '3rd Preparatory' => 'الصف الثالث الإعدادي',
        '3rd Preparatory (Certificate)' => 'الصف الثالث الإعدادي',
        '1st Secondary' => 'الصف الأول الثانوي',
        '2nd Secondary' => 'الصف الثاني الثانوي',
        '3rd Secondary' => 'الصف الثالث الثانوي',
        '3rd Secondary (Thanawya Amma)' => 'الصف الثالث الثانوي',
    ];

    public function __construct(
        protected UserRepositoryInterface $userRepository
    ) {}

    /**
     * Retrieve or initialize the student's profile, goals, privacy settings, and preferences.
     */
    public function getProfile(User $student): array
    {
        if (empty($student->student_code)) {
            $student->forceFill([
                'student_code' => $this->userRepository->generateUniqueStudentCode(),
            ])->save();
        }

        $profile = StudentProfile::firstOrCreate(
            ['student_id' => $student->id],
            [
                'stage' => StageType::THANAWYA->value,
                'grade_ar' => 'الصف الثالث الثانوي',
                'track_ar' => 'علمي علوم',
                'daily_study_goal_minutes' => 120,
                'weekly_exams_goal' => 3,
                'target_score_percent' => 90,
                'show_on_leaderboard' => true,
                'display_name_mode' => 'full',
                'preferences' => [
                    'playbackSpeed' => '1.0x',
                ],
            ]
        );

        return $this->formatProfile($student->fresh(), $profile);
    }

    /**
     * Update the student's profile, study goals, privacy controls, and preferences.
     */
    public function updateProfile(User $student, array $data): array
    {
        return $this->transaction(function () use ($student, $data) {
            $userUpdates = [];

            if (array_key_exists('name', $data) && is_string($data['name'])) {
                $cleanName = trim(strip_tags($data['name']));
                if ($cleanName !== '') {
                    $userUpdates['name'] = $cleanName;
                }
            }

            if (array_key_exists('phone', $data)) {
                $cleanPhone = is_string($data['phone']) ? trim(strip_tags($data['phone'])) : '';
                $userUpdates['phone'] = $cleanPhone !== '' ? $cleanPhone : null;
            }

            if (array_key_exists('avatar', $data)) {
                if ($data['avatar'] === null || $data['avatar'] === '') {
                    $this->cleanupExistingAvatarFiles($student);
                    $userUpdates['avatar'] = null;
                } elseif (is_string($data['avatar']) && strlen($data['avatar']) <= 2048) {
                    $userUpdates['avatar'] = trim($data['avatar']);
                }
            }

            if (! empty($userUpdates)) {
                $student->update($userUpdates);
            }

            $profile = StudentProfile::firstOrCreate(
                ['student_id' => $student->id],
                [
                    'stage' => StageType::THANAWYA->value,
                    'grade_ar' => 'الصف الثالث الثانوي',
                    'track_ar' => 'علمي علوم',
                    'daily_study_goal_minutes' => 120,
                    'weekly_exams_goal' => 3,
                    'target_score_percent' => 90,
                    'show_on_leaderboard' => true,
                    'display_name_mode' => 'full',
                ]
            );

            $profileFields = [
                'stage',
                'grade_ar',
                'track_ar',
                'governorate',
                'school_name',
                'center_name',
                'parent_name',
                'parent_phone',
                'daily_study_goal_minutes',
                'weekly_exams_goal',
                'target_score_percent',
                'show_on_leaderboard',
                'display_name_mode',
                'nickname',
                'target_goal',
                'notifications',
            ];

            $profileUpdates = [];
            foreach ($profileFields as $field) {
                if (array_key_exists($field, $data)) {
                    $profileUpdates[$field] = $data[$field];
                }
            }

            if (isset($profileUpdates['grade_ar']) && is_string($profileUpdates['grade_ar'])) {
                $normalizedGrade = $this->normalizeGradeAr($profileUpdates['grade_ar']);
                $profileUpdates['grade_ar'] = $normalizedGrade;
                if (! isset($profileUpdates['stage']) || $profileUpdates['stage'] === null) {
                    $profileUpdates['stage'] = $this->resolveStageFromGrade($normalizedGrade);
                }
            }

            if (array_key_exists('preferences', $data) && is_array($data['preferences'])) {
                $existingPrefs = is_array($profile->preferences) ? $profile->preferences : [];
                $incomingPrefs = $data['preferences'];

                if (isset($incomingPrefs['playbackSpeed'])) {
                    $incomingPrefs['playbackSpeed'] = $this->normalizePlaybackSpeed((string) $incomingPrefs['playbackSpeed']);
                }

                $profileUpdates['preferences'] = array_merge($existingPrefs, $incomingPrefs);
            }

            if (! empty($profileUpdates)) {
                $profile->update($profileUpdates);
            }

            $this->logSecurityEvent(
                'student.profile.updated',
                ['updated_fields' => array_keys(array_merge($userUpdates, $profileUpdates))],
                'info',
                $profile
            );

            return $this->formatProfile($student->fresh(), $profile->fresh());
        });
    }

    /**
     * Securely upload and replace the student's avatar image.
     */
    public function uploadAvatar(User $student, UploadedFile $file): array
    {
        $realPath = $file->getRealPath();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $realPath ? $finfo->file($realPath) : $file->getMimeType();

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (! isset($allowedMimes[$detectedMime])) {
            throw ValidationException::withMessages([
                'avatar' => [__('messages.invalid_image_signature')],
            ]);
        }

        return $this->transaction(function () use ($student, $file, $allowedMimes, $detectedMime) {
            $profile = StudentProfile::firstOrCreate(['student_id' => $student->id]);
            $extension = $allowedMimes[$detectedMime];
            $binary = file_get_contents($file->getRealPath());

            $disk = (! app()->environment('testing') && config('filesystems.disks.supabase.key'))
                ? 'supabase'
                : 'public';

            $filename = ($disk === 'supabase')
                ? 'users/profiles/'.$student->id.'_'.Str::uuid().'.'.$extension
                : 'avatars/students/'.Str::uuid().'.'.$extension;

            $stored = false;
            try {
                $stored = (bool) Storage::disk($disk)->put($filename, $binary);
            } catch (Throwable) {
                $stored = false;
            }

            // Fallback gracefully to local public disk if remote storage fails
            if (! $stored && $disk === 'supabase') {
                $disk = 'public';
                $filename = 'avatars/students/'.Str::uuid().'.'.$extension;
                $stored = (bool) Storage::disk('public')->put($filename, $binary);
            }

            if (! $stored) {
                throw ValidationException::withMessages([
                    'avatar' => [__('messages.general_error')],
                ]);
            }

            $this->cleanupExistingAvatarFiles($student);

            $publicUrl = ($disk === 'supabase' && method_exists(Storage::disk($disk), 'temporaryUrl'))
                ? Storage::disk($disk)->temporaryUrl($filename, now()->addDays(7))
                : Storage::disk($disk)->url($filename);

            $student->update(['avatar' => $publicUrl]);

            StoredFile::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $student->id,
                'entity_type' => 'user_avatar',
                'entity_id' => (string) $student->id,
                'original_filename' => basename($file->getClientOriginalName()),
                'storage_disk' => $disk,
                'storage_path' => $filename,
                'mime_type' => $detectedMime,
                'file_size_bytes' => (int) $file->getSize(),
                'storage_provider' => $disk === 'supabase' ? 'supabase' : 'local',
                'upload_status' => 'completed',
            ]);

            $this->logSecurityEvent(
                'student.avatar.uploaded',
                ['mime_type' => $detectedMime, 'disk' => $disk],
                'info',
                $profile
            );

            return $this->formatProfile($student->fresh(), $profile->fresh());
        });
    }

    /**
     * Remove the student's uploaded avatar image so the UI reverts to WhatsApp-style initials.
     */
    public function deleteAvatar(User $student): array
    {
        return $this->transaction(function () use ($student) {
            $profile = StudentProfile::firstOrCreate(['student_id' => $student->id]);

            $this->cleanupExistingAvatarFiles($student);
            $student->update(['avatar' => null]);

            $this->logSecurityEvent(
                'student.avatar.deleted',
                [],
                'info',
                $profile
            );

            return $this->formatProfile($student->fresh(), $profile->fresh());
        });
    }

    protected function cleanupExistingAvatarFiles(User $student): void
    {
        if ($student->avatar && preg_match('#/storage/(avatars/students/[a-zA-Z0-9\-]+\.(jpg|png|webp))$#', $student->avatar, $matches)) {
            Storage::disk('public')->delete($matches[1]);
        }

        $previousFiles = StoredFile::where('user_id', $student->id)
            ->where('entity_type', 'user_avatar')
            ->get();

        foreach ($previousFiles as $storedFile) {
            try {
                if ($storedFile->storage_disk && $storedFile->storage_path) {
                    Storage::disk($storedFile->storage_disk)->delete($storedFile->storage_path);
                }
            } catch (Throwable) {
                // Ignore remote cleanup failures so profile update never blocks
            }
            $storedFile->delete();
        }
    }

    protected function normalizeGradeAr(?string $grade): string
    {
        $clean = trim((string) $grade);
        if ($clean === '') {
            return 'الصف الثالث الثانوي';
        }

        return self::LEGACY_GRADE_MAP[$clean] ?? $clean;
    }

    protected function resolveStageFromGrade(string $gradeAr): string
    {
        if (str_contains($gradeAr, 'الثالث الثانوي')) {
            return StageType::THANAWYA->value;
        }
        if (str_contains($gradeAr, 'الثانوي')) {
            return StageType::SECONDARY->value;
        }
        if (str_contains($gradeAr, 'الإعدادي')) {
            return StageType::PREP->value;
        }
        if (str_contains($gradeAr, 'الابتدائي')) {
            return StageType::PRIMARY->value;
        }

        return StageType::THANAWYA->value;
    }

    protected function normalizePlaybackSpeed(string $raw): string
    {
        $num = (float) rtrim(trim($raw), 'xX');
        $allowed = [0.75, 1.0, 1.25, 1.5, 1.75, 2.0];
        if (! in_array($num, $allowed, true)) {
            $num = 1.0;
        }

        return $num === 1.0 ? '1.0x' : ($num === 2.0 ? '2.0x' : "{$num}x");
    }

    protected function formatProfile(User $student, StudentProfile $profile): array
    {
        $gradeAr = $this->normalizeGradeAr($profile->grade_ar);
        $prefs = is_array($profile->preferences) ? $profile->preferences : [];

        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'emailVerified' => $student->email_verified_at !== null,
            'phone' => $student->phone,
            'avatar' => $student->avatar ?: null,
            'studentCode' => $student->student_code,
            'stage' => $profile->stage && in_array($profile->stage, StageType::values(), true)
                ? $profile->stage
                : $this->resolveStageFromGrade($gradeAr),
            'gradeAr' => $gradeAr,
            'trackAr' => $profile->track_ar ?: '',
            'governorate' => $profile->governorate ?: '',
            'schoolName' => $profile->school_name ?: '',
            'centerName' => $profile->center_name ?: '',
            'parentName' => $profile->parent_name ?: '',
            'parentPhone' => $profile->parent_phone ?: '',
            'dailyStudyGoalMinutes' => (int) ($profile->daily_study_goal_minutes ?: 120),
            'weeklyExamsGoal' => (int) ($profile->weekly_exams_goal ?: 3),
            'targetScorePercent' => (int) ($profile->target_score_percent ?: 90),
            'currentStreakDays' => StudentActivityService::resolveEffectiveStreak($profile),
            'longestStreakDays' => (int) ($profile->longest_streak_days ?? 0),
            'lastActiveDate' => $profile->last_active_date?->toDateString(),
            'timezone' => $profile->timezone ?: StudentActivityService::DEFAULT_TIMEZONE,
            'showOnLeaderboard' => (bool) $profile->show_on_leaderboard,
            'displayNameMode' => in_array($profile->display_name_mode, ['full', 'initials', 'nickname'], true)
                ? $profile->display_name_mode
                : 'full',
            'nickname' => $profile->nickname ?: '',
            'preferences' => [
                'playbackSpeed' => $this->normalizePlaybackSpeed((string) ($prefs['playbackSpeed'] ?? '1.0x')),
                'theme' => isset($prefs['theme']) && in_array($prefs['theme'], ['light', 'dark'], true)
                    ? $prefs['theme']
                    : null,
                'language' => isset($prefs['language']) && in_array($prefs['language'], ['ar', 'en'], true)
                    ? $prefs['language']
                    : null,
            ],
        ];
    }
}
