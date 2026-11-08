<?php

namespace App\Models;

use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LeagueParticipant extends Model
{
    use HasFactory, SoftDeletes, HasSecurityAudit;

    protected $table = 'league_participants';

    protected $fillable = [
        'uuid',
        'course_league_id',
        'student_id',
        'rank',
        'previous_rank',
        'xp_points',
        'streak_days',
        'badge_title_ar',
        'tier',
        'movement',
        'school_ar',
        'group_ar',
        'perfect_exams_count',
        'exams_solved_count',
        'homework_rate',
        'attendance_rate',
    ];

    protected function casts(): array
    {
        return [
            'rank'                => 'integer',
            'previous_rank'       => 'integer',
            'xp_points'           => 'integer',
            'streak_days'         => 'integer',
            'perfect_exams_count' => 'integer',
            'exams_solved_count'  => 'integer',
            'homework_rate'       => 'integer',
            'attendance_rate'     => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(CourseLeague::class, 'course_league_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}

