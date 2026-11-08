<?php

namespace App\Models;

use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Traits\BelongsToTeacher;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CourseLeague extends Model
{
    use HasFactory, SoftDeletes, BelongsToTeacher;

    protected $table = 'course_leagues';

    protected $fillable = [
        'teacher_id',
        'course_id',
        'study_group_id',
        'title_ar',
        'course_name_ar',
        'group_name_ar',
        'duration_type',
        'duration_days',
        'is_active',
        'starts_at',
        'ends_at',
        'total_competitors',
    ];

    protected function casts(): array
    {
        return [
            'duration_type'     => LeagueDuration::class,
            'duration_days'     => 'integer',
            'is_active'         => 'boolean',
            'starts_at'         => 'datetime',
            'ends_at'           => 'datetime',
            'total_competitors' => 'integer',
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

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function studyGroup(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(LeagueParticipant::class, 'course_league_id')->orderBy('rank');
    }

    /**
     * Compute remaining days in current league round.
     */
    public function roundDaysRemaining(): int
    {
        if (!$this->ends_at) {
            return 0;
        }

        $diff = (int) Carbon::now()->diffInDays($this->ends_at, false);
        return max(0, $diff);
    }

    /**
     * Recalculate participant rankings, badges, tiers and movement arrows based on XP.
     */
    public function recalculateRanksAndMetrics(): self
    {
        $participants = $this->participants()
            ->reorder()
            ->orderByDesc('xp_points')
            ->orderByDesc('streak_days')
            ->orderBy('id')
            ->get();

        $rank = 1;
        foreach ($participants as $participant) {
            $prev = $participant->rank;
            $newRank = $rank;

            $movement = 'same';
            if ($prev !== null && $prev > 0) {
                if ($newRank < $prev) {
                    $movement = 'up';
                } elseif ($newRank > $prev) {
                    $movement = 'down';
                }
            }

            $tier = LeagueTier::resolveFromXp((int) $participant->xp_points)->value;

            $badge = match ($newRank) {
                1       => 'بطل الكورس',
                2       => 'وصيف الكورس',
                3       => 'المركز الثالث',
                4, 5, 6 => 'نجم متألق',
                default => 'منافس نشط',
            };

            $participant->update([
                'previous_rank'  => $prev,
                'rank'           => $newRank,
                'movement'       => $movement,
                'tier'           => $tier,
                'badge_title_ar' => $badge,
            ]);

            $rank++;
        }

        $this->update([
            'total_competitors' => $participants->count(),
        ]);

        return $this;
    }
}
