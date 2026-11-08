<?php

namespace App\Models;

use App\Enums\RiskLevel;
use App\Enums\RiskSignalStatus;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StudentRiskSignal extends Model
{
    use HasFactory, SoftDeletes, HasSecurityAudit;

    protected $table = 'student_risk_signals';

    protected $fillable = [
        'uuid',
        'teacher_id',
        'student_id',
        'study_group_id',
        'risk_level',
        'risk_reasons_ar',
        'attendance_drop_pct',
        'quiz_score_drop_pct',
        'attendance_pct',
        'avg_exam_score',
        'last_active_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'risk_level'          => RiskLevel::class,
            'status'              => RiskSignalStatus::class,
            'attendance_drop_pct' => 'float',
            'quiz_score_drop_pct' => 'float',
            'attendance_pct'      => 'integer',
            'avg_exam_score'      => 'float',
            'last_active_at'      => 'datetime',
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function studyGroup(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }
}

