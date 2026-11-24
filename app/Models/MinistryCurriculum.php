<?php

namespace App\Models;

use App\Support\MinistryCurriculumCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MinistryCurriculum extends Model
{
    use SoftDeletes;

    protected $table = 'ministry_curricula';

    /**
     * SECURITY: Explicit allow-list; ownership/audit keys are set in service layer.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'title_ar',
        'title_en',
        'subject_ar',
        'subject_en',
        'grade_ar',
        'grade_subtitle_ar',
        'grade_subtitle_en',
        'stage',
        'term',
        'tracks',
        'description_ar',
        'description_en',
        'textbook_url',
        'exam_code',
        'units',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'tracks' => 'array',
            'units' => 'array',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Seed the canonical Egyptian Ministry of Education catalog once when the table is empty.
     * Safe for repeated calls and idempotent under RefreshDatabase or fresh deployments.
     */
    public static function ensureCatalogSeeded(): void
    {
        if (static::withTrashed()->exists()) {
            return;
        }

        $now = now();
        $rows = [];
        foreach (MinistryCurriculumCatalog::curricula() as $index => $item) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'code' => (string) $item['code'],
                'title_ar' => (string) $item['titleAr'],
                'title_en' => $item['titleEn'] ?? null,
                'subject_ar' => (string) $item['subjectAr'],
                'subject_en' => $item['subjectEn'] ?? null,
                'grade_ar' => (string) $item['gradeAr'],
                'grade_subtitle_ar' => $item['gradeSubtitleAr'] ?? null,
                'grade_subtitle_en' => $item['gradeSubtitleEn'] ?? null,
                'stage' => (string) ($item['stage'] ?? MinistryCurriculumCatalog::stageForGrade((string) $item['gradeAr'])),
                'term' => (string) ($item['term'] ?? 'full_year'),
                'tracks' => json_encode($item['tracks'] ?? ['عام'], JSON_UNESCAPED_UNICODE),
                'description_ar' => $item['descriptionAr'] ?? null,
                'description_en' => $item['descriptionEn'] ?? null,
                'textbook_url' => $item['textbookUrl'] ?? MinistryCurriculumCatalog::OFFICIAL_PORTAL_URL,
                'exam_code' => $item['examCode'] ?? null,
                'units' => json_encode($item['units'] ?? [], JSON_UNESCAPED_UNICODE),
                'is_published' => true,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            static::query()->insertOrIgnore($rows);
        }
    }

    /**
     * Convert this Eloquent record to the canonical catalog array structure used by StudentMinistryService.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        $units = is_array($this->units) ? array_values($this->units) : [];

        return [
            'id' => $this->uuid,
            'uuid' => $this->uuid,
            'code' => $this->code,
            'titleAr' => $this->title_ar,
            'titleEn' => $this->title_en ?: $this->title_ar,
            'subjectAr' => $this->subject_ar,
            'subjectEn' => $this->subject_en ?: $this->subject_ar,
            'gradeAr' => $this->grade_ar,
            'gradeSubtitleAr' => $this->grade_subtitle_ar ?: $this->grade_ar,
            'gradeSubtitleEn' => $this->grade_subtitle_en ?: ($this->grade_subtitle_ar ?: $this->grade_ar),
            'stage' => $this->stage,
            'term' => $this->term ?: 'full_year',
            'tracks' => is_array($this->tracks) ? array_values($this->tracks) : [],
            'descriptionAr' => $this->description_ar,
            'descriptionEn' => $this->description_en,
            'textbookUrl' => $this->textbook_url ?: MinistryCurriculumCatalog::OFFICIAL_PORTAL_URL,
            'examCode' => $this->exam_code,
            'units' => $units,
            'isPublished' => (bool) $this->is_published,
            'sortOrder' => (int) $this->sort_order,
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
