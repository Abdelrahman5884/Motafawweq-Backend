<?php

namespace App\Models;

use App\Enums\AcademicStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\JoinMethod;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class GroupEnrollment extends Model
{
    use HasFactory, SoftDeletes, HasSecurityAudit;

    protected $fillable = [
        'uuid',
        'study_group_id',
        'student_id',
        'status',
        'join_method',
        'student_barcode',
        'student_passcode',
        'student_name_override',
        'student_phone_override',
        'parent_name_ar',
        'parent_phone',
        'attendance_rate',
        'attended_sessions',
        'total_sessions',
        'avg_quiz_score',
        'streak_days',
        'academic_status',
        'requested_at',
        'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'status'            => EnrollmentStatus::class,
            'join_method'       => JoinMethod::class,
            'academic_status'   => AcademicStatus::class,
            'attendance_rate'   => 'integer',
            'attended_sessions' => 'integer',
            'total_sessions'    => 'integer',
            'avg_quiz_score'    => 'integer',
            'streak_days'       => 'integer',
            'requested_at'      => 'datetime',
            'enrolled_at'       => 'datetime',
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

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Generate a unique high-entropy student barcode for physical ID cards and camera attendance.
     */
    public static function generateUniqueBarcode(int $groupId, int $studentId): string
    {
        do {
            $randomHex = strtoupper(bin2hex(random_bytes(3)));
            $barcode = sprintf('STD-%03d-%04d-%s', $groupId, $studentId, $randomHex);
        } while (static::where('student_barcode', $barcode)->exists());

        return $barcode;
    }

    /**
     * Generate a 4-digit numeric passcode for quick PIN attendance.
     */
    public static function generatePasscode(): string
    {
        return (string) random_int(1000, 9999);
    }
}

