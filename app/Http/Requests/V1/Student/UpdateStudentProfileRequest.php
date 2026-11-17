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

        $gradeAr = $this->input('grade_ar') ?? $this->input('gradeAr');
        if ($gradeAr !== null) {
            $merged['grade_ar'] = trim(strip_tags((string) $gradeAr));
        }

        $schoolName = $this->input('school_name') ?? $this->input('schoolName');
        if ($schoolName !== null) {
            $merged['school_name'] = trim(strip_tags((string) $schoolName));
        }

        $parentName = $this->input('parent_name') ?? $this->input('parentName');
        if ($parentName !== null) {
            $merged['parent_name'] = trim(strip_tags((string) $parentName));
        }

        $parentPhone = $this->input('parent_phone') ?? $this->input('parentPhone');
        if ($parentPhone !== null) {
            $merged['parent_phone'] = trim(strip_tags((string) $parentPhone));
        }

        $dailyGoal = $this->input('daily_study_goal_minutes') ?? $this->input('dailyStudyGoalMinutes');
        if ($dailyGoal !== null) {
            $merged['daily_study_goal_minutes'] = (int) $dailyGoal;
        }

        $weeklyExams = $this->input('weekly_exams_goal') ?? $this->input('weeklyExamsGoal');
        if ($weeklyExams !== null) {
            $merged['weekly_exams_goal'] = (int) $weeklyExams;
        }

        $targetScore = $this->input('target_score_percent') ?? $this->input('targetScorePercent');
        if ($targetScore !== null) {
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

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'stage' => ['nullable', 'string', Rule::in(StageType::values())],
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'governorate' => ['nullable', 'string', 'max:100'],
            'school_name' => ['nullable', 'string', 'max:150'],
            'parent_name' => ['nullable', 'string', 'max:150'],
            'parent_phone' => ['nullable', 'string', 'max:30'],
            'daily_study_goal_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            'weekly_exams_goal' => ['nullable', 'integer', 'min:1', 'max:30'],
            'target_score_percent' => ['nullable', 'integer', 'min:50', 'max:100'],
            'show_on_leaderboard' => ['nullable', 'boolean'],
            'display_name_mode' => ['nullable', 'string', Rule::in(['full', 'initials', 'nickname'])],
            'nickname' => ['nullable', 'string', 'max:60'],
            'preferences' => ['nullable', 'array'],
            'notifications' => ['nullable', 'array'],
        ];
    }
}
