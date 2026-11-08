<?php

namespace App\Models;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GroupAttendanceRecord extends Model
{
    use HasFactory, HasSecurityAudit;

    protected $fillable = [
        'uuid',
        'attendance_session_id',
        'study_group_id',
        'group_enrollment_id',
        'student_id',
        'status',
        'recorded_method',
        'scanned_at',
        'recorded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'          => AttendanceStatus::class,
            'recorded_method' => AttendanceRecordMethod::class,
            'scanned_at'      => 'datetime',
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

    public function session(): BelongsTo
    {
        return $this->belongsTo(GroupAttendanceSession::class, 'attendance_session_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(GroupEnrollment::class, 'group_enrollment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
