<?php

namespace App\Services\V1\Admin;

use App\Models\PlatformChampion;
use App\Models\RepublicLeague;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\BaseService;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminChampionService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * List all champions for admin management (legacy unpaginated helper).
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return PlatformChampion::query()
            ->orderByDesc('is_pinned')
            ->orderBy('display_order')
            ->orderByDesc('added_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PlatformChampion $c) => $c->toFrontendArray())
            ->values()
            ->all();
    }

    /**
     * Paginated, searchable, grade-filterable list of Honor Roll Champions for Admin UI.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listPaginated(array $filters = []): array
    {
        $normalizedGrade = RepublicLeague::normalizeGrade($filters['grade'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $pinnedFilter = strtolower(trim((string) ($filters['pinned'] ?? 'all')));
        $sourceFilter = strtolower(trim((string) ($filters['source'] ?? 'all')));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(3, (int) ($filters['per_page'] ?? 12)));

        $query = PlatformChampion::query();

        if ($normalizedGrade !== null) {
            $query->where('grade_ar', $normalizedGrade);
        }

        if ($pinnedFilter === 'pinned') {
            $query->where('is_pinned', true);
        } elseif ($pinnedFilter === 'hidden') {
            $query->where('is_pinned', false);
        }

        if (in_array($sourceFilter, ['manual', 'republic_league'], true)) {
            $query->where('source', $sourceFilter);
        }

        if ($search !== '') {
            $escaped = addcslashes(mb_strtolower($search), '%_\\');
            $like = "%{$escaped}%";
            $query->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(name_ar) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(name_en, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(school_ar, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(grade_ar, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(title_ar, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(month_ar, \'\')) LIKE ?', [$like]);
            });
        }

        $paginator = $query
            ->orderByDesc('is_pinned')
            ->orderBy('display_order')
            ->orderByDesc('added_date')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())
            ->map(fn (PlatformChampion $c) => $c->toFrontendArray())
            ->values()
            ->all();

        // Global summary counts for header badges (1 lightweight aggregate query)
        $summaryRow = PlatformChampion::query()
            ->selectRaw('COUNT(*) as total_cnt')
            ->selectRaw('SUM(CASE WHEN is_pinned = 1 THEN 1 ELSE 0 END) as pinned_cnt')
            ->selectRaw('SUM(CASE WHEN is_pinned = 0 THEN 1 ELSE 0 END) as hidden_cnt')
            ->selectRaw("SUM(CASE WHEN source = 'republic_league' THEN 1 ELSE 0 END) as league_cnt")
            ->first();

        return [
            'items' => $items,
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem() ?? 0,
                    'to' => $paginator->lastItem() ?? 0,
                ],
                'summary' => [
                    'total' => (int) ($summaryRow?->total_cnt ?? 0),
                    'pinned' => (int) ($summaryRow?->pinned_cnt ?? 0),
                    'hidden' => (int) ($summaryRow?->hidden_cnt ?? 0),
                    'fromLeague' => (int) ($summaryRow?->league_cnt ?? 0),
                ],
            ],
        ];
    }

    /**
     * Cached read-only list of pinned champions for public landing page & league slider.
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicPinned(): array
    {
        $cacheKey = $this->settingsService->cacheKey('public:champions:pinned');

        return Cache::remember($cacheKey, now()->addMinutes(10), function (): array {
            $pinned = PlatformChampion::query()
                ->where('is_pinned', true)
                ->orderBy('display_order')
                ->orderByDesc('added_date')
                ->orderByDesc('id')
                ->limit(24)
                ->get();

            return $pinned->map(fn (PlatformChampion $c) => $c->toFrontendArray())->values()->all();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $admin, array $data): array
    {
        $source = ($data['source'] ?? 'manual') === 'republic_league' ? 'republic_league' : 'manual';

        $champion = new PlatformChampion([
            'student_id' => $data['student_id'] ?? null,
            'republic_league_id' => $data['republic_league_id'] ?? null,
            'source' => $source,
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? $data['name_ar'],
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? ($data['title_ar'] ?? null),
            'grade_ar' => $data['grade_ar'] ?? null,
            'month_ar' => $data['month_ar'] ?? null,
            'month_en' => $data['month_en'] ?? ($data['month_ar'] ?? null),
            'school_ar' => $data['school_ar'] ?? null,
            'school_en' => $data['school_en'] ?? ($data['school_ar'] ?? null),
            'score_ar' => $data['score_ar'] ?? null,
            'score_en' => $data['score_en'] ?? ($data['score_ar'] ?? null),
            'rank_ar' => $data['rank_ar'] ?? null,
            'rank_en' => $data['rank_en'] ?? ($data['rank_ar'] ?? null),
            'streak_ar' => $data['streak_ar'] ?? null,
            'streak_en' => $data['streak_en'] ?? ($data['streak_ar'] ?? null),
            'badge_ar' => $data['badge_ar'] ?? 'كأس التفوق الشهري',
            'badge_en' => $data['badge_en'] ?? 'Monthly Excellence Cup',
            'image_url' => $data['image_url'] ?? '/characters/real-male-champ-1.png',
            'is_pinned' => array_key_exists('is_pinned', $data) ? (bool) $data['is_pinned'] : true,
            'display_order' => (int) ($data['display_order'] ?? 0),
            'added_date' => $data['added_date'] ?? now()->toDateString(),
        ]);

        $internalMeta = ['created_by' => $admin->id];
        if ($source === 'republic_league') {
            $internalMeta['approved_by'] = $admin->id;
            $internalMeta['approved_at'] = now();
        }

        $champion->forceFill($internalMeta)->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.champion.created', [
            'admin_id' => $admin->id,
            'champion_uuid' => $champion->uuid,
            'name_ar' => $champion->name_ar,
            'grade_ar' => $champion->grade_ar,
            'source' => $source,
        ], 'info', $champion);

        return $champion->toFrontendArray();
    }

    /**
     * Review, customize (text + photo), and approve an auto-detected Republic League nominee for the homepage slider.
     * Prevents duplicates by upserting cleanly per (student_id, republic_league_id) or explicit champion_uuid.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function approveNominee(User $admin, array $data): array
    {
        $studentId = isset($data['student_id']) ? (int) $data['student_id'] : null;
        if (! $studentId) {
            throw ValidationException::withMessages([
                'student_id' => [__('messages.validation_failed')],
            ]);
        }

        $leagueId = isset($data['republic_league_id']) ? (int) $data['republic_league_id'] : null;
        $championUuid = isset($data['champion_uuid']) && is_string($data['champion_uuid'])
            ? trim($data['champion_uuid'])
            : null;

        /** @var PlatformChampion|null $existing */
        $existing = null;
        if ($championUuid) {
            $existing = PlatformChampion::query()->where('uuid', $championUuid)->first();
        }
        if (! $existing) {
            $existing = PlatformChampion::query()
                ->where('student_id', $studentId)
                ->when($leagueId !== null, fn ($q) => $q->where('republic_league_id', $leagueId))
                ->when($leagueId === null, fn ($q) => $q->whereNull('republic_league_id'))
                ->first();
        }

        $payload = [
            'student_id' => $studentId,
            'republic_league_id' => $leagueId,
            'source' => 'republic_league',
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? $data['name_ar'],
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? ($data['title_ar'] ?? null),
            'grade_ar' => $data['grade_ar'] ?? null,
            'month_ar' => $data['month_ar'] ?? null,
            'month_en' => $data['month_en'] ?? ($data['month_ar'] ?? null),
            'school_ar' => $data['school_ar'] ?? null,
            'school_en' => $data['school_en'] ?? ($data['school_ar'] ?? null),
            'score_ar' => $data['score_ar'] ?? null,
            'score_en' => $data['score_en'] ?? ($data['score_ar'] ?? null),
            'rank_ar' => $data['rank_ar'] ?? null,
            'rank_en' => $data['rank_en'] ?? ($data['rank_ar'] ?? null),
            'streak_ar' => $data['streak_ar'] ?? null,
            'streak_en' => $data['streak_en'] ?? ($data['streak_ar'] ?? null),
            'badge_ar' => $data['badge_ar'] ?? 'كأس التفوق الشهري',
            'badge_en' => $data['badge_en'] ?? 'Monthly Excellence Cup',
            'image_url' => $data['image_url'] ?? '/characters/real-male-champ-1.png',
            'is_pinned' => array_key_exists('is_pinned', $data) ? (bool) $data['is_pinned'] : true,
            'display_order' => (int) ($data['display_order'] ?? ($existing?->display_order ?? 1)),
            'added_date' => $data['added_date'] ?? ($existing?->added_date?->toDateString() ?? now()->toDateString()),
        ];

        $champion = $this->transaction(function () use ($admin, $existing, $payload): PlatformChampion {
            if ($existing) {
                $existing->fill($payload);
                $existing->forceFill([
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                ])->save();

                return $existing->fresh();
            }

            $created = new PlatformChampion($payload);
            $created->forceFill([
                'created_by' => $admin->id,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ])->save();

            return $created->fresh();
        });

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.champion.nominee_approved', [
            'admin_id' => $admin->id,
            'champion_uuid' => $champion->uuid,
            'student_id' => $studentId,
            'republic_league_id' => $leagueId,
            'grade_ar' => $champion->grade_ar,
            'is_pinned' => (bool) $champion->is_pinned,
        ], 'info', $champion);

        return $champion->toFrontendArray();
    }

    /**
     * Securely validate, sanitize (re-encode to strip EXIF/polyglots when GD is available),
     * and store a custom champion portrait image on the public disk.
     *
     * @return array{url: string, path: string, mime: string, size: int}
     */
    public function uploadPhoto(User $admin, UploadedFile $file): array
    {
        try {
            $rawBytes = $file->get();
        } catch (Throwable) {
            $rawBytes = false;
        }

        if (! is_string($rawBytes) || $rawBytes === '') {
            throw ValidationException::withMessages([
                'photo' => [__('messages.admin.invalid_champion_photo')],
            ]);
        }

        // 1. Magic-byte MIME verification directly from buffer (cross-platform, ignores client headers/extensions)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->buffer($rawBytes);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (! isset($allowedMimes[$detectedMime])) {
            throw ValidationException::withMessages([
                'photo' => [__('messages.admin.invalid_champion_photo')],
            ]);
        }

        // 2. Real image header & dimensions verification (guards against decompression bombs / fake files)
        $imgInfo = @getimagesizefromstring($rawBytes);
        if (! is_array($imgInfo) || empty($imgInfo[0]) || empty($imgInfo[1])) {
            throw ValidationException::withMessages([
                'photo' => [__('messages.admin.invalid_champion_photo')],
            ]);
        }

        [$width, $height] = [(int) $imgInfo[0], (int) $imgInfo[1]];
        if ($width < 60 || $height < 60 || $width > 4096 || $height > 4096) {
            throw ValidationException::withMessages([
                'photo' => [__('messages.admin.invalid_champion_photo_dimensions')],
            ]);
        }

        $extension = $allowedMimes[$detectedMime];

        // 3. Re-encode through GD when available to strip EXIF metadata and neutralize polyglot payloads
        $cleanBinary = $this->sanitizeImageBinary($rawBytes, $detectedMime) ?? $rawBytes;

        $relativePath = 'champions/'.Str::uuid().'.'.$extension;
        $stored = (bool) Storage::disk('public')->put($relativePath, $cleanBinary);

        if (! $stored) {
            throw ValidationException::withMessages([
                'photo' => [__('messages.general_error')],
            ]);
        }

        $publicUrl = '/storage/'.$relativePath;
        $storedSize = strlen($cleanBinary);

        StoredFile::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'entity_type' => 'platform_champion_photo',
            'entity_id' => (string) $admin->id,
            'original_filename' => basename($file->getClientOriginalName()),
            'storage_disk' => 'public',
            'storage_path' => $relativePath,
            'mime_type' => $detectedMime,
            'file_size_bytes' => $storedSize,
            'storage_provider' => 'local',
            'upload_status' => 'completed',
        ]);

        $this->logSecurityEvent('admin.champion.photo_uploaded', [
            'admin_id' => $admin->id,
            'path' => $relativePath,
            'mime_type' => $detectedMime,
            'size_bytes' => $storedSize,
        ], 'info');

        return [
            'url' => $publicUrl,
            'path' => $relativePath,
            'mime' => $detectedMime,
            'size' => $storedSize,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $admin, string $uuid, array $data): array
    {
        /** @var PlatformChampion $champion */
        $champion = PlatformChampion::query()->where('uuid', $uuid)->firstOrFail();

        $allowed = [
            'student_id', 'republic_league_id', 'source',
            'name_ar', 'name_en', 'title_ar', 'title_en', 'grade_ar',
            'month_ar', 'month_en', 'school_ar', 'school_en',
            'score_ar', 'score_en', 'rank_ar', 'rank_en',
            'streak_ar', 'streak_en', 'badge_ar', 'badge_en',
            'image_url', 'is_pinned', 'display_order', 'added_date',
        ];

        $champion->fill(array_intersect_key($data, array_flip($allowed)))->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.champion.updated', [
            'admin_id' => $admin->id,
            'champion_uuid' => $champion->uuid,
            'grade_ar' => $champion->grade_ar,
        ], 'info', $champion);

        return $champion->fresh()->toFrontendArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function togglePin(User $admin, string $uuid): array
    {
        /** @var PlatformChampion $champion */
        $champion = PlatformChampion::query()->where('uuid', $uuid)->firstOrFail();

        $champion->update(['is_pinned' => ! (bool) $champion->is_pinned]);
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.champion.pin_toggled', [
            'admin_id' => $admin->id,
            'champion_uuid' => $champion->uuid,
            'is_pinned' => (bool) $champion->is_pinned,
        ], 'info', $champion);

        return $champion->fresh()->toFrontendArray();
    }

    public function delete(User $admin, string $uuid): void
    {
        /** @var PlatformChampion $champion */
        $champion = PlatformChampion::query()->where('uuid', $uuid)->firstOrFail();

        $champion->delete();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.champion.deleted', [
            'admin_id' => $admin->id,
            'champion_uuid' => $champion->uuid,
        ], 'warning', $champion);
    }

    /**
     * Re-encode uploaded raster image using GD to strip EXIF and any non-pixel trailing bytes.
     */
    private function sanitizeImageBinary(string $rawBytes, string $mime): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $resource = @imagecreatefromstring($rawBytes);

            if ($resource === false) {
                return null;
            }

            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($resource, false);
                imagesavealpha($resource, true);
            }

            ob_start();
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($resource, null, 88),
                'image/png' => imagepng($resource, null, 6),
                'image/webp' => function_exists('imagewebp') ? imagewebp($resource, null, 88) : false,
                default => false,
            };
            $binary = ob_get_clean();
            imagedestroy($resource);

            return ($ok && is_string($binary) && $binary !== '') ? $binary : null;
        } catch (Throwable) {
            return null;
        }
    }
}
