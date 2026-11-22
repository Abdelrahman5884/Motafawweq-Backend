<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StudentMinistryExamAttempt extends Model
{
    use BelongsToStudent;

    protected $table = 'student_ministry_exam_attempts';

    protected $fillable = [
        'uuid',
        'student_id',
        'exam_code',
        'subject_ar',
        'grade_ar',
        'title_ar',
        'started_at',
        'expires_at',
        'submitted_at',
        'answers_json',
        'score',
        'full_mark',
        'percentage',
        'is_passed',
        'is_full_mark',
        'correct_count',
        'wrong_count',
        'unanswered_count',
        'tab_switches_count',
        'xp_awarded',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'answers_json' => 'array',
            'score' => 'float',
            'full_mark' => 'integer',
            'percentage' => 'float',
            'is_passed' => 'boolean',
            'is_full_mark' => 'boolean',
            'correct_count' => 'integer',
            'wrong_count' => 'integer',
            'unanswered_count' => 'integer',
            'tab_switches_count' => 'integer',
            'xp_awarded' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            if (empty($attempt->uuid)) {
                $attempt->uuid = (string) Str::uuid();
            }
        });
    }
}
