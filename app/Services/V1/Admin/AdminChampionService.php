<?php

namespace App\Services\V1\Admin;

use App\Models\PlatformChampion;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Support\Facades\Cache;

class AdminChampionService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * List all champions for admin management.
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
                ->limit(20)
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
        $champion = new PlatformChampion([
            'student_id' => $data['student_id'] ?? null,
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? $data['name_ar'],
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? ($data['title_ar'] ?? null),
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

        $champion->forceFill(['created_by' => $admin->id])->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.champion.created', [
            'admin_id' => $admin->id,
            'champion_uuid' => $champion->uuid,
            'name_ar' => $champion->name_ar,
        ], 'info', $champion);

        return $champion->toFrontendArray();
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
            'student_id', 'name_ar', 'name_en', 'title_ar', 'title_en',
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
}
