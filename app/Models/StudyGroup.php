<?php

namespace App\Models;

use App\Traits\BelongsToTeacher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StudyGroup extends Model
{
    use BelongsToTeacher, HasFactory, SoftDeletes;

    protected $fillable = [
        'teacher_id',
        'center_id',
        'center_branch_id',
        'center_room_id',
        'center_teacher_id',
        'teacher_name_override',
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
            'price_egp' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->join_code)) {
                $model->join_code = static::generateUniqueJoinCode();
            }
        });

        static::saving(function (self $model) {
            if (! empty($model->join_code)) {
                $model->join_code = strtoupper(trim((string) $model->join_code));
            }
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(User::class, 'center_id');
    }

    public function centerBranch(): BelongsTo
    {
        return $this->belongsTo(CenterBranch::class, 'center_branch_id');
    }

    public function centerRoom(): BelongsTo
    {
        return $this->belongsTo(CenterRoom::class, 'center_room_id');
    }

    public function centerTeacher(): BelongsTo
    {
        return $this->belongsTo(CenterTeacher::class, 'center_teacher_id');
    }

    public function isOwnedByCenter(int|User $center): bool
    {
        $centerId = $center instanceof User ? $center->id : $center;

        return $this->center_id !== null && (int) $this->center_id === (int) $centerId;
    }

    /**
     * Strictly assert center ownership or throw AuthorizationException with IDOR audit logging.
     *
     * @throws AuthorizationException
     */
    public function assertOwnedByCenter(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $user->isCenter() || ! $this->isOwnedByCenter($user)) {
            $this->logSecurityEvent(
                'center.idor_attempt_blocked',
                [
                    'attempted_by_user_id' => $user->id,
                    'resource_owner_id' => $this->center_id ?? $this->teacher_id,
                    'resource_type' => static::class,
                    'resource_uuid' => $this->uuid,
                ],
                'warning',
                $this
            );

            throw new AuthorizationException(__('messages.center.forbidden_resource_owner'));
        }
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

    public function announcements(): HasMany
    {
        return $this->hasMany(GroupAnnouncement::class, 'study_group_id')
            ->orderByDesc('created_at');
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
        } while (static::withTrashed()->where('join_code', $code)->exists());

        return $code;
    }
}
