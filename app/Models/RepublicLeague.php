<?php

namespace App\Models;

use App\Enums\LeagueDuration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RepublicLeague extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Canonical 6 educational grades supported in the Republic League (1st Preparatory → 3rd Secondary).
     *
     * @var list<array{key: string, ar: string, en: string, stage: string, aliases: list<string>}>
     */
    public const SUPPORTED_GRADES = [
        [
            'key' => 'prep_1',
            'ar' => 'الصف الأول الإعدادي',
            'en' => '1st Preparatory',
            'stage' => 'prep',
            'aliases' => ['الصف الأول الإعدادي', 'أولى إعدادي', '1st Preparatory'],
        ],
        [
            'key' => 'prep_2',
            'ar' => 'الصف الثاني الإعدادي',
            'en' => '2nd Preparatory',
            'stage' => 'prep',
            'aliases' => ['الصف الثاني الإعدادي', 'ثانية إعدادي', '2nd Preparatory'],
        ],
        [
            'key' => 'prep_3',
            'ar' => 'الصف الثالث الإعدادي',
            'en' => '3rd Preparatory',
            'stage' => 'prep',
            'aliases' => ['الصف الثالث الإعدادي', 'ثالثة إعدادي', '3rd Preparatory', '3rd Preparatory (Certificate)'],
        ],
        [
            'key' => 'sec_1',
            'ar' => 'الصف الأول الثانوي',
            'en' => '1st Secondary',
            'stage' => 'secondary',
            'aliases' => ['الصف الأول الثانوي', 'أولى ثانوي', '1st Secondary'],
        ],
        [
            'key' => 'sec_2',
            'ar' => 'الصف الثاني الثانوي',
            'en' => '2nd Secondary',
            'stage' => 'secondary',
            'aliases' => ['الصف الثاني الثانوي', 'ثانية ثانوي', '2nd Secondary'],
        ],
        [
            'key' => 'sec_3',
            'ar' => 'الصف الثالث الثانوي',
            'en' => '3rd Secondary',
            'stage' => 'thanawya',
            'aliases' => ['الصف الثالث الثانوي', 'ثالثة ثانوي', '3rd Secondary', '3rd Secondary (Thanawya Amma)'],
        ],
    ];

    protected $table = 'republic_leagues';

    protected $fillable = [
        'uuid',
        'started_by',
        'title_ar',
        'grade_ar',
        'duration_type',
        'duration_days',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_type' => LeagueDuration::class,
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Normalize an incoming grade string/key/alias into its canonical Arabic label, or null for "all".
     */
    public static function normalizeGrade(?string $raw): ?string
    {
        $clean = trim((string) $raw);
        if ($clean === '' || strtolower($clean) === 'all' || $clean === 'الكل') {
            return null;
        }

        foreach (self::SUPPORTED_GRADES as $grade) {
            if ($clean === $grade['key'] || $clean === $grade['ar'] || strcasecmp($clean, $grade['en']) === 0) {
                return $grade['ar'];
            }
            foreach ($grade['aliases'] as $alias) {
                if (strcasecmp($clean, $alias) === 0) {
                    return $grade['ar'];
                }
            }
        }

        return null;
    }

    /**
     * Return all accepted DB grade aliases (Arabic + legacy English) for a canonical grade.
     *
     * @return list<string>
     */
    public static function aliasesForGrade(string $canonicalGradeAr): array
    {
        foreach (self::SUPPORTED_GRADES as $grade) {
            if ($grade['ar'] === $canonicalGradeAr) {
                return $grade['aliases'];
            }
        }

        return [$canonicalGradeAr];
    }

    /**
     * Return canonical English label for a canonical Arabic grade.
     */
    public static function gradeLabelEn(?string $canonicalGradeAr): string
    {
        if (! $canonicalGradeAr) {
            return 'All Grades (1st Prep – 3rd Sec)';
        }

        foreach (self::SUPPORTED_GRADES as $grade) {
            if ($grade['ar'] === $canonicalGradeAr) {
                return $grade['en'];
            }
        }

        return $canonicalGradeAr;
    }

    /**
     * Return all canonical Arabic grade labels.
     *
     * @return list<string>
     */
    public static function supportedGradeLabels(): array
    {
        return array_column(self::SUPPORTED_GRADES, 'ar');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function champions(): HasMany
    {
        return $this->hasMany(PlatformChampion::class, 'republic_league_id');
    }

    public function roundDaysRemaining(): int
    {
        if (! $this->is_active || ! $this->ends_at) {
            return 0;
        }

        $hours = Carbon::now()->diffInHours($this->ends_at, false);

        return $hours > 0 ? (int) ceil($hours / 24) : 0;
    }

    /**
     * Auto-close any active Republic League rounds whose ends_at timestamp has passed.
     */
    public static function closeExpiredRounds(): void
    {
        static::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now())
            ->update(['is_active' => false]);
    }

    /**
     * Retrieve the currently active Admin-started Republic League (auto-closing expired rounds).
     * When $gradeAr is specified, prioritizes a grade-specific active round and falls back to a platform-wide round.
     */
    public static function currentActive(?string $gradeAr = null): ?self
    {
        static::closeExpiredRounds();

        $normalized = self::normalizeGrade($gradeAr);
        $now = Carbon::now();

        $baseQuery = static::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            });

        if ($normalized !== null) {
            /** @var self|null $gradeSpecific */
            $gradeSpecific = (clone $baseQuery)
                ->where('grade_ar', $normalized)
                ->latest('starts_at')
                ->first();

            if ($gradeSpecific) {
                return $gradeSpecific;
            }

            /** @var self|null $platformWide */
            $platformWide = (clone $baseQuery)
                ->whereNull('grade_ar')
                ->latest('starts_at')
                ->first();

            return $platformWide;
        }

        /** @var self|null $platformWideFirst */
        $platformWideFirst = (clone $baseQuery)
            ->whereNull('grade_ar')
            ->latest('starts_at')
            ->first();

        if ($platformWideFirst) {
            return $platformWideFirst;
        }

        /** @var self|null $anyActive */
        $anyActive = (clone $baseQuery)
            ->latest('starts_at')
            ->first();

        return $anyActive;
    }
}
