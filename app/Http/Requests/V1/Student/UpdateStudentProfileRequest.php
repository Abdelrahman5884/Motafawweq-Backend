<?php

namespace App\Http\Requests\V1\Student;

use App\Enums\StageType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateStudentProfileRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStudent();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $merged = [];

        if ($this->has('phone')) {
            $rawPhone = trim((string) ($this->input('phone') ?? ''));
            $merged['phone'] = $rawPhone !== '' ? $rawPhone : null;
        }

        $gradeAr = $this->input('grade_ar') ?? $this->input('gradeAr');
        if ($gradeAr !== null) {
            $clean = trim(strip_tags((string) $gradeAr));
            $merged['grade_ar'] = $clean !== '' ? $clean : null;
        }

        $trackAr = $this->input('track_ar') ?? $this->input('trackAr') ?? $this->input('track');
        if ($trackAr !== null) {
            $clean = trim(strip_tags((string) $trackAr));
            $merged['track_ar'] = $clean !== '' ? $clean : null;
        }

        if ($this->has('governorate')) {
            $clean = trim(strip_tags((string) ($this->input('governorate') ?? '')));
            $merged['governorate'] = $clean !== '' ? $clean : null;
        }

        $schoolName = $this->input('school_name') ?? $this->input('schoolName');
        if ($schoolName !== null) {
            $clean = trim(strip_tags((string) $schoolName));
            $merged['school_name'] = $clean !== '' ? $clean : null;
        }

        $centerName = $this->input('center_name') ?? $this->input('centerName') ?? $this->input('center');
        if ($centerName !== null) {
            $clean = trim(strip_tags((string) $centerName));
            $merged['center_name'] = $clean !== '' ? $clean : null;
        }

        $parentName = $this->input('parent_name') ?? $this->input('parentName');
        if ($parentName !== null) {
            $clean = trim(strip_tags((string) $parentName));
            $merged['parent_name'] = $clean !== '' ? $clean : null;
        }

        $parentPhone = $this->input('parent_phone') ?? $this->input('parentPhone');
        if ($parentPhone !== null) {
            $clean = trim(strip_tags((string) $parentPhone));
            $merged['parent_phone'] = $clean !== '' ? $clean : null;
        }

        $dailyGoal = $this->input('daily_study_goal_minutes') ?? $this->input('dailyStudyGoalMinutes');
        if ($dailyGoal !== null && $dailyGoal !== '') {
            $merged['daily_study_goal_minutes'] = (int) $dailyGoal;
        }

        $weeklyExams = $this->input('weekly_exams_goal') ?? $this->input('weeklyExamsGoal');
        if ($weeklyExams !== null && $weeklyExams !== '') {
            $merged['weekly_exams_goal'] = (int) $weeklyExams;
        }

        $targetScore = $this->input('target_score_percent') ?? $this->input('targetScorePercent');
        if ($targetScore !== null && $targetScore !== '') {
            $merged['target_score_percent'] = (int) $targetScore;
        }

        $showLeaderboard = $this->input('show_on_leaderboard') ?? $this->input('showOnLeaderboard');
        if ($showLeaderboard !== null) {
            $merged['show_on_leaderboard'] = filter_var($showLeaderboard, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }

        $displayMode = $this->input('display_name_mode') ?? $this->input('displayNameMode');
        if ($displayMode !== null) {
            $merged['display_name_mode'] = strtolower(trim((string) $displayMode));
        }

        if ($this->has('nickname')) {
            $clean = trim(strip_tags((string) ($this->input('nickname') ?? '')));
            $merged['nickname'] = $clean !== '' ? $clean : null;
        }

        $targetGoal = $this->input('target_goal') ?? $this->input('targetGoal');
        if ($targetGoal !== null) {
            $clean = trim(strip_tags((string) $targetGoal));
            $merged['target_goal'] = $clean !== '' ? $clean : null;
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9+\-\s()]{7,20}$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
            'avatar' => ['nullable', 'string', 'max:2048'],
            'stage' => ['nullable', 'string', Rule::in(StageType::values())],
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'track_ar' => ['nullable', 'string', 'max:100'],
            'governorate' => ['nullable', 'string', 'max:100'],
            'school_name' => ['nullable', 'string', 'max:150'],
            'center_name' => ['nullable', 'string', 'max:150'],
            'parent_name' => ['nullable', 'string', 'max:150'],
            'parent_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'daily_study_goal_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'weekly_exams_goal' => ['nullable', 'integer', 'min:1', 'max:30'],
            'target_score_percent' => ['nullable', 'integer', 'min:50', 'max:100'],
            'show_on_leaderboard' => ['nullable', 'boolean'],
            'display_name_mode' => ['nullable', 'string', Rule::in(['full', 'initials', 'nickname'])],
            'nickname' => ['nullable', 'string', 'max:60'],
            'target_goal' => ['nullable', 'string', 'max:255'],
            'preferences' => ['nullable', 'array'],
            'preferences.playbackSpeed' => [
                'sometimes',
                'string',
                Rule::in(['0.75', '1', '1.0', '1.25', '1.5', '1.75', '2', '2.0', '0.75x', '1.0x', '1.25x', '1.5x', '1.75x', '2.0x']),
            ],
            'preferences.theme' => ['sometimes', 'string', Rule::in(['light', 'dark'])],
            'preferences.language' => ['sometimes', 'string', Rule::in(['ar', 'en'])],
            'notifications' => ['nullable', 'array'],
        ];
    }
}
