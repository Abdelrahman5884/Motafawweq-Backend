<?php

namespace App\Models;

use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StudyGroup extends Model
{
    use HasFactory, SoftDeletes, BelongsToTeacher;

    protected $fillable = [
        'teacher_id',
        'course_id',
        'name_ar',
        'name_en',
        'subject_ar',
        'subject_en',
        'grade_ar',
        'grade_en',
        'center_name',
        'hall_name',
        'schedule_ar',
        'schedule_en',
        'max_students',
        'price_egp',
        'join_code',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'max_students' => 'integer',
            'price_egp'    => 'float',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(GroupScheduleSlot::class, 'study_group_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(GroupEnrollment::class, 'study_group_id');
    }

    public function enrolledEnrollments(): HasMany
    {
        return $this->hasMany(GroupEnrollment::class, 'study_group_id')
            ->where('status', 'enrolled');
    }

    public function pendingEnrollments(): HasMany
    {
        return $this->hasMany(GroupEnrollment::class, 'study_group_id')
            ->where('status', 'pending');
    }

    /**
     * Generate an alphanumeric high-entropy, teacher-friendly join code.
     * Example: BIO-7492 or GRP-8391
     */
    public static function generateUniqueJoinCode(string $prefix = 'GRP'): string
    {
        $cleanPrefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix));
        if (strlen($cleanPrefix) < 3) {
            $cleanPrefix = 'GRP';
        }

        do {
            $suffix = random_int(1000, 9999);
            $code = "{$cleanPrefix}-{$suffix}";
        } while (static::where('join_code', $code)->exists());

        return $code;
    }
}

