<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupAttendanceSession extends Model
{
    use HasFactory, BelongsToTeacher;

    protected $fillable = [
        'study_group_id',
        'teacher_id',
        'schedule_slot_id',
        'session_date',
        'title_ar',
        'status',
        'notes',
        'total_enrolled',
        'present_count',
        'absent_count',
        'late_count',
        'attendance_rate',
    ];

    protected function casts(): array
    {
        return [
            'session_date'    => 'date',
            'total_enrolled'  => 'integer',
            'present_count'   => 'integer',
            'absent_count'    => 'integer',
            'late_count'      => 'integer',
            'attendance_rate' => 'float',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function scheduleSlot(): BelongsTo
    {
        return $this->belongsTo(GroupScheduleSlot::class, 'schedule_slot_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(GroupAttendanceRecord::class, 'attendance_session_id');
    }

    /**
     * Recalculate present, absent, late counts and attendance percentage for this session.
     */
    public function recalculateSummary(): self
    {
        $enrolledCount = (int) $this->group->enrolledEnrollments()->count();
        $present = $this->records()->whereIn('status', [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value])->count();
        $late = $this->records()->where('status', AttendanceStatus::LATE->value)->count();
        $absent = max(0, $enrolledCount - $present);

        $rate = $enrolledCount > 0 ? round(($present / $enrolledCount) * 100, 1) : 0.0;

        $this->update([
            'total_enrolled'  => $enrolledCount,
            'present_count'   => $present,
            'absent_count'    => $absent,
            'late_count'      => $late,
            'attendance_rate' => $rate,
        ]);

        return $this;
    }
}
