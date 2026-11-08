<?php

namespace App\Models;

use App\Enums\TeacherPlanTier;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TeacherProfile extends Model
{
    use HasFactory, HasSecurityAudit;

    /**
     * SECURITY: plan_tier, is_verified_educator, rating, and reviews_count
     * are intentionally excluded from $fillable to prevent privilege/reputation tampering.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name_ar',
        'name_en',
        'title_ar',
        'title_en',
        'subject_ar',
        'subject_en',
        'bio_ar',
        'bio_en',
        'whatsapp_phone',
        'stages_taught',
        'two_factor_enabled',
        'notify_whatsapp_intervention',
        'notify_new_enrollment',
        'notify_exam_submission',
        'auto_watermark_enabled',
        'preferred_theme',
        'preferred_lang',
    ];

    protected function casts(): array
    {
        return [
            'stages_taught'                => 'array',
            'plan_tier'                    => TeacherPlanTier::class,
            'is_verified_educator'         => 'boolean',
            'two_factor_enabled'           => 'boolean',
            'notify_whatsapp_intervention' => 'boolean',
            'notify_new_enrollment'        => 'boolean',
            'notify_exam_submission'       => 'boolean',
            'auto_watermark_enabled'       => 'boolean',
            'rating'                       => 'float',
            'reviews_count'                => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $profile) {
            if (empty($profile->uuid)) {
                $profile->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwnedBy(int|User $teacher): bool
    {
        $teacherId = $teacher instanceof User ? $teacher->id : $teacher;

        return (int) $this->user_id === (int) $teacherId;
    }
}

