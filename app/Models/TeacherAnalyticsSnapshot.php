<?php

namespace App\Models;

use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TeacherAnalyticsSnapshot extends Model
{
    use HasFactory, BelongsToTeacher;

    protected $table = 'teacher_analytics_snapshots';

    protected $fillable = [
        'uuid',
        'teacher_id',
        'metric_date',
        'active_students_count',
        'attendance_avg',
        'quiz_avg',
        'at_risk_count',
        'revenue_total',
        'snapshots_json',
    ];

    protected function casts(): array
    {
        return [
            'metric_date'           => 'date:Y-m-d',
            'active_students_count' => 'integer',
            'attendance_avg'        => 'float',
            'quiz_avg'              => 'float',
            'at_risk_count'         => 'integer',
            'revenue_total'         => 'float',
            'snapshots_json'        => 'array',
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
}

