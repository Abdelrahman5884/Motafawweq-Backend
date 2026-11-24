<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PlatformChampion extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * SECURITY: created_by, approved_by, approved_at, and uuid are managed internally rather than mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'republic_league_id',
        'source',
        'name_ar',
        'name_en',
        'title_ar',
        'title_en',
        'grade_ar',
        'month_ar',
        'month_en',
        'school_ar',
        'school_en',
        'score_ar',
        'score_en',
        'rank_ar',
        'rank_en',
        'streak_ar',
        'streak_en',
        'badge_ar',
        'badge_en',
        'image_url',
        'is_pinned',
        'display_order',
        'added_date',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'display_order' => 'integer',
            'added_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->added_date)) {
                $model->added_date = now()->toDateString();
            }
            if (empty($model->source)) {
                $model->source = 'manual';
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function republicLeague(): BelongsTo
    {
        return $this->belongsTo(RepublicLeague::class, 'republic_league_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Format champion consistently for AdminDashboard & Landing Slider.
     *
     * @return array<string, mixed>
     */
    public function toFrontendArray(): array
    {
        $gradeAr = $this->grade_ar ?: '';

        return [
            'id' => $this->uuid,
            'studentId' => $this->student_id ? (int) $this->student_id : null,
            'republicLeagueId' => $this->republic_league_id ? (int) $this->republic_league_id : null,
            'source' => $this->source ?: 'manual',
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en ?: $this->name_ar,
            'titleAr' => $this->title_ar ?: '',
            'titleEn' => $this->title_en ?: ($this->title_ar ?: ''),
            'gradeAr' => $gradeAr,
            'gradeEn' => $gradeAr !== '' ? RepublicLeague::gradeLabelEn($gradeAr) : '',
            'monthAr' => $this->month_ar ?: '',
            'monthEn' => $this->month_en ?: ($this->month_ar ?: ''),
            'schoolAr' => $this->school_ar ?: '',
            'schoolEn' => $this->school_en ?: ($this->school_ar ?: ''),
            'scoreAr' => $this->score_ar ?: '',
            'scoreEn' => $this->score_en ?: ($this->score_ar ?: ''),
            'rankAr' => $this->rank_ar ?: '',
            'rankEn' => $this->rank_en ?: ($this->rank_ar ?: ''),
            'streakAr' => $this->streak_ar ?: '',
            'streakEn' => $this->streak_en ?: ($this->streak_ar ?: ''),
            'badgeAr' => $this->badge_ar ?: 'كأس التفوق الشهري',
            'badgeEn' => $this->badge_en ?: 'Monthly Excellence Cup',
            'image' => $this->image_url ?: '/characters/real-male-champ-1.png',
            'isPinned' => (bool) $this->is_pinned,
            'displayOrder' => (int) $this->display_order,
            'addedDate' => $this->added_date?->toDateString() ?? $this->created_at?->toDateString(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
        ];
    }
}
