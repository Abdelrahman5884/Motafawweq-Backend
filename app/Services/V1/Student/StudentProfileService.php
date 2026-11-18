<?php

namespace App\Services\V1\Student;

use App\Models\StoredFile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\BaseService;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentProfileService extends BaseService
{
    /**
     * Retrieve or initialize the student's profile, goals, privacy settings, and preferences.
     */
    public function getProfile(User $student): array
    {
        $profile = StudentProfile::firstOrCreate(
            ['student_id' => $student->id],
            [
                'stage' => 'high',
                'grade_ar' => 'الصف الثالث الثانوي',
                'track_ar' => 'علمي علوم',
                'daily_study_goal_minutes' => 120,
                'weekly_exams_goal' => 3,
                'target_score_percent' => 90,
                'show_on_leaderboard' => true,
                'display_name_mode' => 'full',
            ]
        );

        return $this->formatProfile($student, $profile);
    }

    /**
     * Update the student's profile, study goals, privacy controls, and preferences.
     */
    public function updateProfile(User $student, array $data): array
    {
        return $this->transaction(function () use ($student, $data) {
            $userUpdates = [];
            if (isset($data['name'])) {
                $userUpdates['name'] = trim(strip_tags((string) $data['name']));
            }
            if (array_key_exists('phone', $data)) {
                $userUpdates['phone'] = $data['phone'] ? trim(strip_tags((string) $data['phone'])) : null;
            }
            if (array_key_exists('avatar', $data) && is_string($data['avatar']) && strlen($data['avatar']) <= 2048) {
                $userUpdates['avatar'] = trim($data['avatar']);
            }
            if (! empty($userUpdates)) {
                $student->update($userUpdates);
            }

            $profile = StudentProfile::firstOrCreate(['student_id' => $student->id]);

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
                'preferences',
                'notifications',
            ];

            $profileUpdates = [];
            foreach ($profileFields as $field) {
                if (array_key_exists($field, $data)) {
                    $profileUpdates[$field] = $data[$field];
                }
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

            $disk = (! app()->environment('testing') && config('filesystems.disks.supabase.key'))
                ? 'supabase'
                : 'public';

            $filename = ($disk === 'supabase')
                ? 'users/profiles/'.$student->id.'_'.Str::uuid().'.'.$extension
                : 'avatars/students/'.Str::uuid().'.'.$extension;

            Storage::disk($disk)->put($filename, file_get_contents($file->getRealPath()));

            if ($student->avatar && preg_match('#/storage/(avatars/students/[a-zA-Z0-9\-]+\.(jpg|png|webp))$#', $student->avatar, $matches)) {
                Storage::disk('public')->delete($matches[1]);
            }

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
                ['mime_type' => $detectedMime],
                'info',
                $profile
            );

            return $this->formatProfile($student->fresh(), $profile->fresh());
        });
    }

    protected function formatProfile(User $student, StudentProfile $profile): array
    {
        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => $student->phone,
            'avatar' => $student->avatar,
            'studentCode' => $student->student_code,
            'stage' => $profile->stage,
            'gradeAr' => $profile->grade_ar,
            'trackAr' => $profile->track_ar,
            'governorate' => $profile->governorate,
            'schoolName' => $profile->school_name,
            'centerName' => $profile->center_name,
            'parentName' => $profile->parent_name,
            'parentPhone' => $profile->parent_phone,
            'dailyStudyGoalMinutes' => (int) $profile->daily_study_goal_minutes,
            'weeklyExamsGoal' => (int) $profile->weekly_exams_goal,
            'targetScorePercent' => (int) $profile->target_score_percent,
            'showOnLeaderboard' => (bool) $profile->show_on_leaderboard,
            'displayNameMode' => $profile->display_name_mode,
            'nickname' => $profile->nickname,
            'targetGoal' => $profile->target_goal,
            'preferences' => $profile->preferences ?? [],
            'notifications' => $profile->notifications ?? [],
        ];
    }
}
