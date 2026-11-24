<?php

namespace App\Services\V1\Admin;

use App\Models\MinistryCurriculum;
use App\Models\User;
use App\Services\BaseService;
use App\Support\MinistryCurriculumCatalog;
use App\Support\YouTubeUrlParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminMinistryCurriculumService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * List all Ministry of Education curricula across all 12 grades (1st Primary -> 3rd Secondary)
     * with live KPI summary and per-grade counts.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listCurricula(array $filters = []): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        $perPage = min(max((int) ($filters['per_page'] ?? 12), 1), 100);
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;
        $stage = isset($filters['stage']) ? trim((string) $filters['stage']) : null;
        $grade = isset($filters['grade']) ? trim((string) $filters['grade']) : null;
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $term = isset($filters['term']) ? strtolower(trim((string) $filters['term'])) : null;

        $query = MinistryCurriculum::query()
            ->with(['updater:id,name', 'creator:id,name'])
            ->orderBy('sort_order')
            ->orderByDesc('updated_at');

        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $query->where(function (Builder $q) use ($safe): void {
                $q->where('title_ar', 'like', "%{$safe}%")
                    ->orWhere('title_en', 'like', "%{$safe}%")
                    ->orWhere('subject_ar', 'like', "%{$safe}%")
                    ->orWhere('subject_en', 'like', "%{$safe}%")
                    ->orWhere('grade_ar', 'like', "%{$safe}%")
                    ->orWhere('code', 'like', "%{$safe}%");
            });
        }

        if ($stage !== null && $stage !== '' && $stage !== 'all') {
            if ($stage === 'secondary_all') {
                $query->whereIn('stage', ['stage-secondary', 'stage-thanawya']);
            } else {
                $query->where('stage', $stage);
            }
        }

        if ($grade !== null && $grade !== '' && $grade !== 'all') {
            $query->where('grade_ar', $grade);
        }

        if ($status === 'published') {
            $query->where('is_published', true);
        } elseif ($status === 'draft') {
            $query->where('is_published', false);
        }

        if ($term !== null && in_array($term, ['full_year', 'first', 'second'], true)) {
            $query->where('term', $term);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        // Compute aggregate summary in a single lightweight pass
        $allForSummary = MinistryCurriculum::query()
            ->get(['stage', 'grade_ar', 'is_published', 'units']);

        $publishedCount = 0;
        $draftCount = 0;
        $totalUnits = 0;
        $totalLessons = 0;
        $totalVideos = 0;
        $totalResources = 0;
        $byStage = [
            'stage-primary' => 0,
            'stage-prep' => 0,
            'secondary_all' => 0,
        ];
        $byGrade = [];

        foreach ($allForSummary as $row) {
            if ($row->is_published) {
                $publishedCount++;
            } else {
                $draftCount++;
            }

            if ($row->stage === 'stage-primary') {
                $byStage['stage-primary']++;
            } elseif ($row->stage === 'stage-prep') {
                $byStage['stage-prep']++;
            } else {
                $byStage['secondary_all']++;
            }

            $byGrade[$row->grade_ar] = ($byGrade[$row->grade_ar] ?? 0) + 1;

            $units = is_array($row->units) ? $row->units : [];
            $totalUnits += count($units);
            foreach ($units as $u) {
                if (is_array($u)) {
                    $lessons = $this->hydrateUnitLessons($u);
                    $totalLessons += count($lessons);
                    foreach ($lessons as $les) {
                        if (! empty($les['youtubeId'])) {
                            $totalVideos++;
                        }
                        $totalResources += count((array) ($les['attachments'] ?? []));
                    }
                    $totalResources += count((array) ($u['resources'] ?? []));
                }
            }
        }

        return [
            'summary' => [
                'total' => $allForSummary->count(),
                'published' => $publishedCount,
                'draft' => $draftCount,
                'totalUnits' => $totalUnits,
                'totalLessons' => $totalLessons,
                'totalVideos' => $totalVideos,
                'totalResources' => $totalResources,
                'coveredGradesCount' => count($byGrade),
                'totalGradesCount' => count(MinistryCurriculumCatalog::availableGrades()),
                'byStage' => $byStage,
                'byGrade' => $byGrade,
            ],
            'gradeCatalog' => MinistryCurriculumCatalog::gradeCatalog(),
            'items' => collect($paginator->items())
                ->map(fn (MinistryCurriculum $c) => $this->formatCurriculum($c))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * Get single Ministry curriculum with full units, lessons, YouTube videos, and resources.
     *
     * @return array<string, mixed>
     */
    public function getCurriculum(string $uuid): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()
            ->with(['updater:id,name', 'creator:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return $this->formatCurriculum($curriculum);
    }

    /**
     * Create a new Ministry of Education course/curriculum cleanly (metadata alone or with initial units).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCurriculum(User $admin, array $payload): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        $normalized = $this->normalizeAndValidatePayload($payload, null);

        /** @var MinistryCurriculum $created */
        $created = $this->transaction(function () use ($admin, $normalized): MinistryCurriculum {
            $model = new MinistryCurriculum($normalized);
            $model->forceFill([
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
            $model->save();

            $this->logSecurityEvent('admin.ministry_curriculum.created', [
                'admin_id' => $admin->id,
                'curriculum_uuid' => $model->uuid,
                'code' => $model->code,
                'grade_ar' => $model->grade_ar,
                'units_count' => count($model->units ?? []),
            ], 'info', $model);

            return $model;
        });

        $this->settingsService->bumpCacheEpoch();

        return $this->formatCurriculum($created->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Update an existing Ministry of Education curriculum metadata (and optionally its units).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCurriculum(User $admin, string $uuid, array $payload): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $normalized = $this->normalizeAndValidatePayload($payload, $curriculum);

        $this->transaction(function () use ($admin, $curriculum, $normalized): void {
            $curriculum->fill($normalized);
            $curriculum->forceFill([
                'updated_by' => $admin->id,
            ])->save();

            $this->logSecurityEvent('admin.ministry_curriculum.updated', [
                'admin_id' => $admin->id,
                'curriculum_uuid' => $curriculum->uuid,
                'code' => $curriculum->code,
                'grade_ar' => $curriculum->grade_ar,
                'units_count' => count($curriculum->units ?? []),
            ], 'info', $curriculum);
        });

        $this->settingsService->bumpCacheEpoch();

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Append a new Unit / Chapter inside a Ministry course.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addUnit(User $admin, string $uuid, array $data): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()->where('uuid', $uuid)->firstOrFail();

        $units = is_array($curriculum->units) ? array_values($curriculum->units) : [];
        if (count($units) >= 40) {
            throw ValidationException::withMessages([
                'units' => ['الحد الأقصى للوحدات في المقرر الواحد هو 40 وحدة.'],
            ]);
        }

        $titleAr = $this->cleanText($data['titleAr'] ?? $data['title_ar'] ?? '', 220);
        if ($titleAr === '') {
            throw ValidationException::withMessages([
                'titleAr' => ['عنوان الوحدة الدراسية مطلوب.'],
            ]);
        }

        $topics = $this->parseTopicsList($data['topics'] ?? []);
        $units[] = [
            'titleAr' => $titleAr,
            'titleEn' => $this->cleanText($data['titleEn'] ?? '', 220) ?: $titleAr,
            'summaryAr' => $this->cleanMultilineText($data['summaryAr'] ?? '', 6000),
            'chaptersCount' => max(1, count($topics)),
            'topics' => $topics,
            'lessons' => [],
            'videos' => [],
            'resources' => [],
        ];

        $this->saveUnitsAndAudit($admin, $curriculum, $units, 'admin.ministry_curriculum.unit_added');

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Update an existing Unit's title, summary, or topics without overwriting its lessons.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateUnit(User $admin, string $uuid, int $unitIndex, array $data): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()->where('uuid', $uuid)->firstOrFail();
        $units = is_array($curriculum->units) ? array_values($curriculum->units) : [];

        if (! isset($units[$unitIndex])) {
            throw ValidationException::withMessages([
                'unitIndex' => ['الوحدة المطلوبة غير موجودة في هذا المقرر.'],
            ]);
        }

        $current = is_array($units[$unitIndex]) ? $units[$unitIndex] : [];
        $titleAr = array_key_exists('titleAr', $data)
            ? $this->cleanText($data['titleAr'], 220)
            : (string) ($current['titleAr'] ?? '');

        if ($titleAr === '') {
            throw ValidationException::withMessages([
                'titleAr' => ['عنوان الوحدة الدراسية مطلوب.'],
            ]);
        }

        $current['titleAr'] = $titleAr;
        if (array_key_exists('titleEn', $data)) {
            $current['titleEn'] = $this->cleanText($data['titleEn'], 220) ?: $titleAr;
        }
        if (array_key_exists('summaryAr', $data)) {
            $current['summaryAr'] = $this->cleanMultilineText($data['summaryAr'], 6000);
        }
        if (array_key_exists('topics', $data)) {
            $current['topics'] = $this->parseTopicsList($data['topics']);
        }

        $lessons = $this->hydrateUnitLessons($current);
        $current['lessons'] = $lessons;
        $current['chaptersCount'] = max(1, count($lessons) ?: count($current['topics'] ?? []));
        $units[$unitIndex] = $current;

        $this->saveUnitsAndAudit($admin, $curriculum, $units, 'admin.ministry_curriculum.unit_updated');

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Remove a Unit from a Ministry course.
     *
     * @return array<string, mixed>
     */
    public function deleteUnit(User $admin, string $uuid, int $unitIndex): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()->where('uuid', $uuid)->firstOrFail();
        $units = is_array($curriculum->units) ? array_values($curriculum->units) : [];

        if (! isset($units[$unitIndex])) {
            throw ValidationException::withMessages([
                'unitIndex' => ['الوحدة المطلوبة غير موجودة.'],
            ]);
        }

        array_splice($units, $unitIndex, 1);

        $this->saveUnitsAndAudit($admin, $curriculum, array_values($units), 'admin.ministry_curriculum.unit_deleted');

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Add a Lesson (حصة: YouTube video and/or written content & attachments) to a unit.
     * Automatically initializes Unit 1 if the course currently has zero units.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addLesson(User $admin, string $uuid, array $data): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()->where('uuid', $uuid)->firstOrFail();
        $units = is_array($curriculum->units) ? array_values($curriculum->units) : [];

        $unitIndex = isset($data['unitIndex']) ? max(0, (int) $data['unitIndex']) : 0;

        if (empty($units)) {
            $autoUnitTitle = $this->cleanText($data['unitTitleAr'] ?? '', 220) ?: 'الوحدة الأولى: دروس المقرر';
            $units[] = [
                'titleAr' => $autoUnitTitle,
                'titleEn' => $autoUnitTitle,
                'summaryAr' => '',
                'chaptersCount' => 1,
                'topics' => [],
                'lessons' => [],
                'videos' => [],
                'resources' => [],
            ];
            $unitIndex = 0;
        } elseif (! isset($units[$unitIndex])) {
            throw ValidationException::withMessages([
                'unitIndex' => ['يرجى اختيار وحدة صحيحة لإضافة الحصة بداخلها.'],
            ]);
        }

        $unit = is_array($units[$unitIndex]) ? $units[$unitIndex] : [];
        $lessons = $this->hydrateUnitLessons($unit);
        if (count($lessons) >= 60) {
            throw ValidationException::withMessages([
                'lessons' => ['الحد الأقصى للحصص داخل الوحدة الواحدة هو 60 حصة.'],
            ]);
        }

        $lesson = $this->validateAndBuildLesson($data, null);
        $lessons[] = $lesson;

        $units[$unitIndex] = $this->syncUnitWithLessons($unit, $lessons);

        $this->saveUnitsAndAudit($admin, $curriculum, $units, 'admin.ministry_curriculum.lesson_added', [
            'lesson_id' => $lesson['id'],
            'unit_index' => $unitIndex,
        ]);

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Update an existing Lesson inside a unit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateLesson(User $admin, string $uuid, int $unitIndex, string $lessonId, array $data): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()->where('uuid', $uuid)->firstOrFail();
        $units = is_array($curriculum->units) ? array_values($curriculum->units) : [];

        if (! isset($units[$unitIndex])) {
            throw ValidationException::withMessages([
                'unitIndex' => ['الوحدة المطلوبة غير موجودة.'],
            ]);
        }

        $unit = is_array($units[$unitIndex]) ? $units[$unitIndex] : [];
        $lessons = $this->hydrateUnitLessons($unit);

        $foundIdx = null;
        foreach ($lessons as $idx => $l) {
            if (($l['id'] ?? '') === $lessonId) {
                $foundIdx = $idx;
                break;
            }
        }

        if ($foundIdx === null) {
            throw ValidationException::withMessages([
                'lessonId' => ['الحصة المطلوبة غير موجودة في هذه الوحدة.'],
            ]);
        }

        $lessons[$foundIdx] = $this->validateAndBuildLesson($data, $lessons[$foundIdx]);
        $units[$unitIndex] = $this->syncUnitWithLessons($unit, $lessons);

        $this->saveUnitsAndAudit($admin, $curriculum, $units, 'admin.ministry_curriculum.lesson_updated', [
            'lesson_id' => $lessonId,
            'unit_index' => $unitIndex,
        ]);

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Delete a Lesson from a unit.
     *
     * @return array<string, mixed>
     */
    public function deleteLesson(User $admin, string $uuid, int $unitIndex, string $lessonId): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()->where('uuid', $uuid)->firstOrFail();
        $units = is_array($curriculum->units) ? array_values($curriculum->units) : [];

        if (! isset($units[$unitIndex])) {
            throw ValidationException::withMessages([
                'unitIndex' => ['الوحدة المطلوبة غير موجودة.'],
            ]);
        }

        $unit = is_array($units[$unitIndex]) ? $units[$unitIndex] : [];
        $lessons = $this->hydrateUnitLessons($unit);
        $filtered = array_values(array_filter($lessons, fn (array $l) => ($l['id'] ?? '') !== $lessonId));

        $units[$unitIndex] = $this->syncUnitWithLessons($unit, $filtered);

        $this->saveUnitsAndAudit($admin, $curriculum, $units, 'admin.ministry_curriculum.lesson_deleted', [
            'lesson_id' => $lessonId,
            'unit_index' => $unitIndex,
        ]);

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Reorder lessons inside a unit cleanly by ordered lesson IDs.
     *
     * @param  list<string>  $orderedIds
     * @return array<string, mixed>
     */
    public function reorderLessons(User $admin, string $uuid, int $unitIndex, array $orderedIds): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()->where('uuid', $uuid)->firstOrFail();
        $units = is_array($curriculum->units) ? array_values($curriculum->units) : [];

        if (! isset($units[$unitIndex])) {
            throw ValidationException::withMessages([
                'unitIndex' => ['الوحدة المطلوبة غير موجودة.'],
            ]);
        }

        $unit = is_array($units[$unitIndex]) ? $units[$unitIndex] : [];
        $lessons = $this->hydrateUnitLessons($unit);

        $byId = [];
        foreach ($lessons as $l) {
            $byId[(string) $l['id']] = $l;
        }

        $reordered = [];
        foreach ($orderedIds as $id) {
            $key = (string) $id;
            if (isset($byId[$key])) {
                $reordered[] = $byId[$key];
                unset($byId[$key]);
            }
        }
        foreach ($byId as $remaining) {
            $reordered[] = $remaining;
        }

        $units[$unitIndex] = $this->syncUnitWithLessons($unit, $reordered);

        $this->saveUnitsAndAudit($admin, $curriculum, $units, 'admin.ministry_curriculum.lessons_reordered', [
            'unit_index' => $unitIndex,
        ]);

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Toggle published status (live to students vs draft).
     *
     * @return array<string, mixed>
     */
    public function togglePublish(User $admin, string $uuid): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $nextPublished = ! (bool) $curriculum->is_published;
        $curriculum->forceFill([
            'is_published' => $nextPublished,
            'updated_by' => $admin->id,
        ])->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.ministry_curriculum.publish_toggled', [
            'admin_id' => $admin->id,
            'curriculum_uuid' => $curriculum->uuid,
            'code' => $curriculum->code,
            'is_published' => $nextPublished,
        ], 'info', $curriculum);

        return $this->formatCurriculum($curriculum->fresh(['updater:id,name', 'creator:id,name']));
    }

    /**
     * Soft-delete a Ministry curriculum with audit logging.
     */
    public function deleteCurriculum(User $admin, string $uuid, ?string $reason = null): void
    {
        MinistryCurriculum::ensureCatalogSeeded();

        /** @var MinistryCurriculum $curriculum */
        $curriculum = MinistryCurriculum::query()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->transaction(function () use ($admin, $curriculum, $reason): void {
            $curriculum->forceFill([
                'is_published' => false,
                'updated_by' => $admin->id,
            ])->save();

            $curriculum->delete();

            $this->logSecurityEvent('admin.ministry_curriculum.deleted', [
                'admin_id' => $admin->id,
                'curriculum_uuid' => $curriculum->uuid,
                'code' => $curriculum->code,
                'grade_ar' => $curriculum->grade_ar,
                'reason' => $reason ? $this->cleanText($reason, 500) : null,
            ], 'warning', $curriculum);
        });

        $this->settingsService->bumpCacheEpoch();
    }

    /**
     * Validate and build a single Lesson array (YouTube video optional if content/attachments exist; strictly validated when provided).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    protected function validateAndBuildLesson(array $data, ?array $existing): array
    {
        $titleAr = array_key_exists('titleAr', $data)
            ? $this->cleanText($data['titleAr'], 220)
            : (string) ($existing['titleAr'] ?? '');

        if ($titleAr === '') {
            throw ValidationException::withMessages([
                'titleAr' => ['عنوان الحصة مطلوب.'],
            ]);
        }

        $rawYt = array_key_exists('youtubeUrl', $data)
            ? trim((string) $data['youtubeUrl'])
            : (string) ($existing['youtubeUrl'] ?? '');

        $parsedYt = null;
        if ($rawYt !== '') {
            $parsedYt = YouTubeUrlParser::parse($rawYt);
            if (! $parsedYt) {
                throw ValidationException::withMessages([
                    'youtubeUrl' => ['رابط يوتيوب غير صالح؛ يرجى إدخال رابط youtube.com أو youtu.be صحيح.'],
                ]);
            }
        }

        $contentAr = array_key_exists('contentAr', $data)
            ? $this->cleanMultilineText($data['contentAr'], 8000)
            : (array_key_exists('notesAr', $data)
                ? $this->cleanMultilineText($data['notesAr'], 8000)
                : (string) ($existing['contentAr'] ?? $existing['notesAr'] ?? ''));

        $rawAttachments = array_key_exists('attachments', $data)
            ? (is_array($data['attachments']) ? array_values($data['attachments']) : [])
            : (array) ($existing['attachments'] ?? []);

        $attachments = [];
        foreach (array_slice($rawAttachments, 0, 15) as $aIdx => $rawAtt) {
            if (! is_array($rawAtt)) {
                continue;
            }
            $aTitle = $this->cleanText($rawAtt['titleAr'] ?? $rawAtt['title'] ?? '', 200);
            $aUrl = trim((string) ($rawAtt['url'] ?? ''));
            if ($aTitle === '' && $aUrl === '') {
                continue;
            }
            if ($aUrl !== '' && ! YouTubeUrlParser::isSafeExternalUrl($aUrl)) {
                throw ValidationException::withMessages([
                    "attachments.{$aIdx}.url" => ['رابط المرفق غير صالح أو غير آمن؛ يجب أن يبدأ بـ https://'],
                ]);
            }
            $aType = strtolower(trim((string) ($rawAtt['type'] ?? 'pdf')));
            if (! in_array($aType, ['pdf', 'summary', 'worksheet', 'link'], true)) {
                $aType = 'pdf';
            }
            $attachments[] = [
                'id' => $this->cleanText($rawAtt['id'] ?? '', 40) ?: ('att-'.Str::lower(Str::random(8))),
                'titleAr' => $aTitle !== '' ? $aTitle : ('ملف مرفق #'.($aIdx + 1)),
                'type' => $aType,
                'url' => $aUrl !== '' ? $aUrl : null,
            ];
        }

        if (! $parsedYt && $contentAr === '' && empty($attachments)) {
            throw ValidationException::withMessages([
                'youtubeUrl' => ['يرجى إضافة رابط فيديو يوتيوب أو كتابة محتوى نصي للحصة أو إرفاق ملف.'],
            ]);
        }

        $duration = array_key_exists('durationMinutes', $data)
            ? ($data['durationMinutes'] !== '' && $data['durationMinutes'] !== null ? max(0, min(600, (int) $data['durationMinutes'])) : null)
            : ($existing['durationMinutes'] ?? null);

        return [
            'id' => (string) ($existing['id'] ?? ('les-'.Str::lower(Str::random(8)))),
            'titleAr' => $titleAr,
            'youtubeId' => $parsedYt['youtubeId'] ?? null,
            'youtubeUrl' => $parsedYt['youtubeUrl'] ?? null,
            'embedUrl' => $parsedYt['embedUrl'] ?? null,
            'thumbnailUrl' => $parsedYt['thumbnailUrl'] ?? null,
            'durationMinutes' => $duration,
            'contentAr' => $contentAr,
            'notesAr' => $contentAr,
            'attachments' => $attachments,
        ];
    }

    /**
     * Hydrate normalized lessons list from a stored unit (gracefully migrating legacy videos[] if lessons[] not set yet).
     *
     * @param  array<string, mixed>  $unit
     * @return list<array<string, mixed>>
     */
    protected function hydrateUnitLessons(array $unit): array
    {
        if (isset($unit['lessons']) && is_array($unit['lessons'])) {
            return array_values(array_map(function ($l, int $idx): array {
                $row = is_array($l) ? $l : [];
                $content = (string) ($row['contentAr'] ?? $row['notesAr'] ?? '');

                return [
                    'id' => (string) ($row['id'] ?? ('les-'.$idx)),
                    'titleAr' => (string) ($row['titleAr'] ?? ('الحصة #'.($idx + 1))),
                    'youtubeId' => $row['youtubeId'] ?? null,
                    'youtubeUrl' => $row['youtubeUrl'] ?? null,
                    'embedUrl' => $row['embedUrl'] ?? null,
                    'thumbnailUrl' => $row['thumbnailUrl'] ?? null,
                    'durationMinutes' => $row['durationMinutes'] ?? null,
                    'contentAr' => $content,
                    'notesAr' => $content,
                    'attachments' => is_array($row['attachments'] ?? null) ? array_values($row['attachments']) : [],
                ];
            }, $unit['lessons'], array_keys($unit['lessons'])));
        }

        $videos = is_array($unit['videos'] ?? null) ? array_values($unit['videos']) : [];
        $lessons = [];
        foreach ($videos as $idx => $v) {
            if (! is_array($v)) {
                continue;
            }
            $notes = (string) ($v['notesAr'] ?? $v['contentAr'] ?? '');
            $lessons[] = [
                'id' => (string) ($v['id'] ?? ('les-vid-'.$idx)),
                'titleAr' => (string) ($v['titleAr'] ?? ('فيديو شرح #'.($idx + 1))),
                'youtubeId' => $v['youtubeId'] ?? null,
                'youtubeUrl' => $v['youtubeUrl'] ?? null,
                'embedUrl' => $v['embedUrl'] ?? null,
                'thumbnailUrl' => $v['thumbnailUrl'] ?? null,
                'durationMinutes' => $v['durationMinutes'] ?? null,
                'contentAr' => $notes,
                'notesAr' => $notes,
                'attachments' => [],
            ];
        }

        return $lessons;
    }

    /**
     * Keep unit['lessons'] and backward-compatible unit['videos'] in lockstep.
     *
     * @param  array<string, mixed>  $unit
     * @param  list<array<string, mixed>>  $lessons
     * @return array<string, mixed>
     */
    protected function syncUnitWithLessons(array $unit, array $lessons): array
    {
        $videos = [];
        foreach ($lessons as $les) {
            if (! empty($les['youtubeId'])) {
                $videos[] = [
                    'id' => $les['id'],
                    'titleAr' => $les['titleAr'],
                    'youtubeId' => $les['youtubeId'],
                    'youtubeUrl' => $les['youtubeUrl'],
                    'embedUrl' => $les['embedUrl'],
                    'thumbnailUrl' => $les['thumbnailUrl'],
                    'durationMinutes' => $les['durationMinutes'],
                    'notesAr' => $les['contentAr'] ?? '',
                ];
            }
        }

        $unit['lessons'] = array_values($lessons);
        $unit['videos'] = $videos;
        $unit['chaptersCount'] = max(1, count($lessons) ?: count((array) ($unit['topics'] ?? [])));

        return $unit;
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @param  array<string, mixed>  $extraContext
     */
    protected function saveUnitsAndAudit(User $admin, MinistryCurriculum $curriculum, array $units, string $event, array $extraContext = []): void
    {
        $this->transaction(function () use ($admin, $curriculum, $units, $event, $extraContext): void {
            $curriculum->forceFill([
                'units' => $units,
                'updated_by' => $admin->id,
            ])->save();

            $this->logSecurityEvent($event, array_merge([
                'admin_id' => $admin->id,
                'curriculum_uuid' => $curriculum->uuid,
                'code' => $curriculum->code,
            ], $extraContext), 'info', $curriculum);
        });

        $this->settingsService->bumpCacheEpoch();
    }

    /**
     * @return list<string>
     */
    protected function parseTopicsList(mixed $rawTopics): array
    {
        if (is_string($rawTopics)) {
            $rawTopics = preg_split('/\r\n|\r|\n/', $rawTopics) ?: [];
        }
        $topics = [];
        if (is_array($rawTopics)) {
            foreach ($rawTopics as $t) {
                $clean = $this->cleanText($t, 200);
                if ($clean !== '') {
                    $topics[] = $clean;
                }
            }
        }

        return array_slice($topics, 0, 30);
    }

    /**
     * Validate, sanitize, and canonicalize all curriculum fields, units, YouTube videos, and resources.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeAndValidatePayload(array $payload, ?MinistryCurriculum $existing): array
    {
        $gradeAr = trim((string) ($payload['gradeAr'] ?? $payload['grade_ar'] ?? $existing?->grade_ar ?? ''));
        if (! MinistryCurriculumCatalog::isValidGrade($gradeAr)) {
            throw ValidationException::withMessages([
                'gradeAr' => ['يرجى اختيار صف دراسي صحيح (من الصف الأول الابتدائي حتى الصف الثالث الثانوي).'],
            ]);
        }

        $titleAr = $this->cleanText($payload['titleAr'] ?? $payload['title_ar'] ?? $existing?->title_ar ?? '', 255);
        if ($titleAr === '') {
            throw ValidationException::withMessages([
                'titleAr' => ['عنوان المقرر باللغة العربية مطلوب.'],
            ]);
        }

        $subjectAr = $this->cleanText($payload['subjectAr'] ?? $payload['subject_ar'] ?? $existing?->subject_ar ?? '', 120);
        if ($subjectAr === '') {
            throw ValidationException::withMessages([
                'subjectAr' => ['اسم المادة الدراسية مطلوب.'],
            ]);
        }

        $titleEn = $this->cleanText($payload['titleEn'] ?? $payload['title_en'] ?? $existing?->title_en ?? '', 255);
        $subjectEn = $this->cleanText($payload['subjectEn'] ?? $payload['subject_en'] ?? $existing?->subject_en ?? '', 120);
        $gradeSubtitleAr = $this->cleanText($payload['gradeSubtitleAr'] ?? $payload['grade_subtitle_ar'] ?? $existing?->grade_subtitle_ar ?? '', 180);
        $gradeSubtitleEn = $this->cleanText($payload['gradeSubtitleEn'] ?? $payload['grade_subtitle_en'] ?? $existing?->grade_subtitle_en ?? '', 180);
        $descriptionAr = $this->cleanMultilineText($payload['descriptionAr'] ?? $payload['description_ar'] ?? $existing?->description_ar ?? '', 4000);
        $descriptionEn = $this->cleanMultilineText($payload['descriptionEn'] ?? $payload['description_en'] ?? $existing?->description_en ?? '', 4000);

        $term = strtolower(trim((string) ($payload['term'] ?? $existing?->term ?? 'full_year')));
        if (! in_array($term, ['full_year', 'first', 'second'], true)) {
            $term = 'full_year';
        }

        $textbookUrl = trim((string) ($payload['textbookUrl'] ?? $payload['textbook_url'] ?? $existing?->textbook_url ?? ''));
        if ($textbookUrl !== '' && ! YouTubeUrlParser::isSafeExternalUrl($textbookUrl)) {
            throw ValidationException::withMessages([
                'textbookUrl' => ['رابط كتاب الوزارة غير صالح أو غير آمن؛ يجب أن يبدأ بـ https://'],
            ]);
        }

        $rawTracks = $payload['tracks'] ?? $existing?->tracks ?? ['عام'];
        $tracks = [];
        if (is_array($rawTracks)) {
            foreach ($rawTracks as $t) {
                $cleanTrack = $this->cleanText($t, 60);
                if ($cleanTrack !== '' && ! in_array($cleanTrack, $tracks, true)) {
                    $tracks[] = $cleanTrack;
                }
            }
        }
        if (empty($tracks)) {
            $tracks = ['عام'];
        }

        $examCode = $this->cleanText($payload['examCode'] ?? $payload['exam_code'] ?? $existing?->exam_code ?? '', 80);

        $units = array_key_exists('units', $payload)
            ? $this->normalizeUnits(is_array($payload['units']) ? $payload['units'] : [])
            : ($existing?->units ?? []);

        $isPublished = array_key_exists('isPublished', $payload)
            ? (bool) $payload['isPublished']
            : (array_key_exists('is_published', $payload)
                ? (bool) $payload['is_published']
                : ($existing ? (bool) $existing->is_published : true));

        $sortOrder = array_key_exists('sortOrder', $payload)
            ? max(0, min(10000, (int) $payload['sortOrder']))
            : (array_key_exists('sort_order', $payload)
                ? max(0, min(10000, (int) $payload['sort_order']))
                : ($existing ? (int) $existing->sort_order : 100));

        $code = $existing?->code ?: $this->generateUniqueCode($gradeAr, $subjectEn ?: $subjectAr, $payload['code'] ?? null);

        return [
            'code' => $code,
            'title_ar' => $titleAr,
            'title_en' => $titleEn !== '' ? $titleEn : null,
            'subject_ar' => $subjectAr,
            'subject_en' => $subjectEn !== '' ? $subjectEn : null,
            'grade_ar' => $gradeAr,
            'grade_subtitle_ar' => $gradeSubtitleAr !== '' ? $gradeSubtitleAr : null,
            'grade_subtitle_en' => $gradeSubtitleEn !== '' ? $gradeSubtitleEn : null,
            'stage' => MinistryCurriculumCatalog::stageForGrade($gradeAr),
            'term' => $term,
            'tracks' => $tracks,
            'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
            'description_en' => $descriptionEn !== '' ? $descriptionEn : null,
            'textbook_url' => $textbookUrl !== '' ? $textbookUrl : MinistryCurriculumCatalog::OFFICIAL_PORTAL_URL,
            'exam_code' => $examCode !== '' ? $examCode : null,
            'units' => $units,
            'is_published' => $isPublished,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * Validate and canonicalize units, their lessons, YouTube videos, and study resources.
     *
     * @param  array<int, mixed>  $rawUnits
     * @return list<array<string, mixed>>
     */
    protected function normalizeUnits(array $rawUnits): array
    {
        if (count($rawUnits) > 40) {
            throw ValidationException::withMessages([
                'units' => ['الحد الأقصى للوحدات في المقرر الواحد هو 40 وحدة.'],
            ]);
        }

        $cleanUnits = [];
        $errors = [];

        foreach (array_values($rawUnits) as $uIdx => $rawUnit) {
            if (! is_array($rawUnit)) {
                continue;
            }

            $uTitleAr = $this->cleanText($rawUnit['titleAr'] ?? $rawUnit['title_ar'] ?? '', 220);
            if ($uTitleAr === '') {
                $errors["units.{$uIdx}.titleAr"] = ['عنوان الوحدة مطلوب.'];

                continue;
            }

            $uTitleEn = $this->cleanText($rawUnit['titleEn'] ?? $rawUnit['title_en'] ?? '', 220);
            $uSummaryAr = $this->cleanMultilineText($rawUnit['summaryAr'] ?? $rawUnit['summary_ar'] ?? '', 6000);
            $topics = $this->parseTopicsList($rawUnit['topics'] ?? []);

            // Normalize either lessons[] or videos[]
            $lessons = [];
            if (isset($rawUnit['lessons']) && is_array($rawUnit['lessons'])) {
                foreach (array_slice(array_values($rawUnit['lessons']), 0, 60) as $lIdx => $rawLesson) {
                    if (! is_array($rawLesson)) {
                        continue;
                    }
                    try {
                        $lessons[] = $this->validateAndBuildLesson($rawLesson, null);
                    } catch (ValidationException $ve) {
                        foreach ($ve->errors() as $k => $msgs) {
                            $errors["units.{$uIdx}.lessons.{$lIdx}.{$k}"] = $msgs;
                        }
                    }
                }
            } else {
                $rawVideos = is_array($rawUnit['videos'] ?? null) ? array_values($rawUnit['videos']) : [];
                foreach (array_slice($rawVideos, 0, 30) as $vIdx => $rawVideo) {
                    if (! is_array($rawVideo)) {
                        continue;
                    }

                    $rawUrl = trim((string) ($rawVideo['youtubeUrl'] ?? $rawVideo['youtube_url'] ?? $rawVideo['url'] ?? ''));
                    $vTitle = $this->cleanText($rawVideo['titleAr'] ?? $rawVideo['title_ar'] ?? $rawVideo['title'] ?? '', 220);
                    if ($rawUrl === '' && $vTitle === '') {
                        continue;
                    }

                    $parsedYt = YouTubeUrlParser::parse($rawUrl);
                    if (! $parsedYt) {
                        $errors["units.{$uIdx}.videos.{$vIdx}.youtubeUrl"] = [
                            'رابط يوتيوب غير صحيح؛ يرجى إدخال رابط يوتيوب صالح مثل https://www.youtube.com/watch?v=... أو https://youtu.be/...',
                        ];

                        continue;
                    }

                    $duration = isset($rawVideo['durationMinutes']) && $rawVideo['durationMinutes'] !== '' && $rawVideo['durationMinutes'] !== null
                        ? max(0, min(600, (int) $rawVideo['durationMinutes']))
                        : null;
                    $notes = $this->cleanMultilineText($rawVideo['notesAr'] ?? $rawVideo['notes_ar'] ?? '', 2000);

                    $lessons[] = [
                        'id' => $this->cleanText($rawVideo['id'] ?? '', 40) ?: ('les-'.Str::lower(Str::random(8))),
                        'titleAr' => $vTitle !== '' ? $vTitle : ('الحصة #'.($vIdx + 1)),
                        'youtubeId' => $parsedYt['youtubeId'],
                        'youtubeUrl' => $parsedYt['youtubeUrl'],
                        'embedUrl' => $parsedYt['embedUrl'],
                        'thumbnailUrl' => $parsedYt['thumbnailUrl'],
                        'durationMinutes' => $duration,
                        'contentAr' => $notes,
                        'notesAr' => $notes,
                        'attachments' => [],
                    ];
                }
            }

            // Extra unit-level study resources
            $resources = [];
            $rawResources = is_array($rawUnit['resources'] ?? null) ? array_values($rawUnit['resources']) : [];
            foreach (array_slice($rawResources, 0, 20) as $rIdx => $rawRes) {
                if (! is_array($rawRes)) {
                    continue;
                }

                $rTitle = $this->cleanText($rawRes['titleAr'] ?? $rawRes['title_ar'] ?? $rawRes['title'] ?? '', 200);
                $rUrl = trim((string) ($rawRes['url'] ?? ''));
                $rContent = $this->cleanMultilineText($rawRes['contentAr'] ?? $rawRes['content_ar'] ?? '', 6000);
                if ($rTitle === '' && $rUrl === '' && $rContent === '') {
                    continue;
                }

                if ($rUrl !== '' && ! YouTubeUrlParser::isSafeExternalUrl($rUrl)) {
                    $errors["units.{$uIdx}.resources.{$rIdx}.url"] = [
                        'رابط المرفق غير صالح أو غير آمن؛ يجب أن يكون رابط http/https خارجي صحيح.',
                    ];

                    continue;
                }

                $rType = strtolower(trim((string) ($rawRes['type'] ?? 'summary')));
                if (! in_array($rType, ['pdf', 'summary', 'worksheet', 'link'], true)) {
                    $rType = $rUrl !== '' ? 'link' : 'summary';
                }

                $resources[] = [
                    'id' => $this->cleanText($rawRes['id'] ?? '', 40) ?: ('res-'.Str::lower(Str::random(8))),
                    'titleAr' => $rTitle !== '' ? $rTitle : ('مرفق تعليمي #'.($rIdx + 1)),
                    'type' => $rType,
                    'url' => $rUrl !== '' ? $rUrl : null,
                    'contentAr' => $rContent !== '' ? $rContent : null,
                ];
            }

            $chaptersCount = isset($rawUnit['chaptersCount']) && is_numeric($rawUnit['chaptersCount'])
                ? max(1, min(60, (int) $rawUnit['chaptersCount']))
                : max(1, count($lessons) ?: count($topics) ?: 1);

            $unitBase = [
                'titleAr' => $uTitleAr,
                'titleEn' => $uTitleEn !== '' ? $uTitleEn : $uTitleAr,
                'summaryAr' => $uSummaryAr,
                'chaptersCount' => $chaptersCount,
                'topics' => $topics,
                'resources' => $resources,
            ];

            $cleanUnits[] = $this->syncUnitWithLessons($unitBase, $lessons);
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $cleanUnits;
    }

    protected function generateUniqueCode(string $gradeAr, string $subjectSeed, mixed $requestedCode): string
    {
        $cleanRequested = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string) $requestedCode))) ?: '';
        if ($cleanRequested !== '' && ! MinistryCurriculum::withTrashed()->where('code', $cleanRequested)->exists()) {
            return substr($cleanRequested, 0, 75);
        }

        $gradeShort = MinistryCurriculumCatalog::shortCodeForGrade($gradeAr);
        $subjectSlug = Str::slug($subjectSeed);
        if ($subjectSlug === '') {
            $subjectSlug = 'sub';
        }
        $base = substr("moe-{$gradeShort}-{$subjectSlug}", 0, 64);

        $candidate = $base;
        $attempt = 1;
        while (MinistryCurriculum::withTrashed()->where('code', $candidate)->exists()) {
            $candidate = substr($base, 0, 56).'-'.Str::lower(Str::random(4));
            if (++$attempt > 10) {
                $candidate = 'moe-'.$gradeShort.'-'.Str::lower(Str::random(8));
                break;
            }
        }

        return $candidate;
    }

    protected function cleanText(mixed $val, int $maxLength): string
    {
        $clean = trim(strip_tags((string) $val));
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return mb_substr($clean, 0, $maxLength);
    }

    protected function cleanMultilineText(mixed $val, int $maxLength): string
    {
        $clean = trim(strip_tags((string) $val));

        return mb_substr($clean, 0, $maxLength);
    }

    /**
     * Format a MinistryCurriculum model cleanly for the Admin Course + Lessons Workspace.
     *
     * @return array<string, mixed>
     */
    protected function formatCurriculum(MinistryCurriculum $c): array
    {
        $units = is_array($c->units) ? array_values($c->units) : [];
        $lessonsCount = 0;
        $videosCount = 0;
        $resourcesCount = 0;
        $chaptersCount = 0;

        $normalizedUnits = array_map(function ($u, int $idx) use (&$lessonsCount, &$videosCount, &$resourcesCount, &$chaptersCount): array {
            $unit = is_array($u) ? $u : [];
            $lessons = $this->hydrateUnitLessons($unit);
            $videos = [];
            foreach ($lessons as $les) {
                if (! empty($les['youtubeId'])) {
                    $videos[] = $les;
                }
                $resourcesCount += count((array) ($les['attachments'] ?? []));
            }
            $resources = is_array($unit['resources'] ?? null) ? array_values($unit['resources']) : [];
            $topics = is_array($unit['topics'] ?? null) ? array_values($unit['topics']) : [];
            $chCount = (int) ($unit['chaptersCount'] ?? max(1, count($lessons) ?: count($topics)));

            $lessonsCount += count($lessons);
            $videosCount += count($videos);
            $resourcesCount += count($resources);
            $chaptersCount += $chCount;

            return [
                'index' => $idx,
                'titleAr' => (string) ($unit['titleAr'] ?? ''),
                'titleEn' => (string) ($unit['titleEn'] ?? ($unit['titleAr'] ?? '')),
                'summaryAr' => (string) ($unit['summaryAr'] ?? ''),
                'chaptersCount' => $chCount,
                'topics' => $topics,
                'lessons' => $lessons,
                'videos' => $videos,
                'resources' => $resources,
            ];
        }, $units, array_keys($units));

        return [
            'id' => $c->uuid,
            'uuid' => $c->uuid,
            'code' => $c->code,
            'titleAr' => $c->title_ar,
            'titleEn' => $c->title_en ?: $c->title_ar,
            'subjectAr' => $c->subject_ar,
            'subjectEn' => $c->subject_en ?: $c->subject_ar,
            'gradeAr' => $c->grade_ar,
            'gradeSubtitleAr' => $c->grade_subtitle_ar ?: '',
            'gradeSubtitleEn' => $c->grade_subtitle_en ?: '',
            'stage' => $c->stage,
            'term' => $c->term ?: 'full_year',
            'tracks' => is_array($c->tracks) ? array_values($c->tracks) : ['عام'],
            'descriptionAr' => $c->description_ar ?: '',
            'descriptionEn' => $c->description_en ?: '',
            'textbookUrl' => $c->textbook_url ?: MinistryCurriculumCatalog::OFFICIAL_PORTAL_URL,
            'examCode' => $c->exam_code,
            'isPublished' => (bool) $c->is_published,
            'sortOrder' => (int) $c->sort_order,
            'unitsCount' => count($normalizedUnits),
            'chaptersCount' => $chaptersCount,
            'lessonsCount' => $lessonsCount,
            'videosCount' => $videosCount,
            'resourcesCount' => $resourcesCount,
            'units' => $normalizedUnits,
            'updatedByName' => $c->updater?->name,
            'updatedAt' => $c->updated_at?->toIso8601String(),
            'lastUpdate' => $c->updated_at?->toDateString(),
        ];
    }
}
