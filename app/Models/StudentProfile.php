<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    use BelongsToStudent, HasFactory;

    protected $fillable = [
        'uuid',
        'student_id',
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

    protected function casts(): array
    {
        return [
            'daily_study_goal_minutes' => 'integer',
            'weekly_exams_goal' => 'integer',
            'target_score_percent' => 'integer',
            'show_on_leaderboard' => 'boolean',
            'preferences' => 'array',
            'notifications' => 'array',
        ];
    }
}
