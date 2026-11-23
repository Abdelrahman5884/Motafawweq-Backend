<?php

namespace App\Services\V1\Admin;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use App\Models\PlatformAnnouncement;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Support\Facades\Cache;

class AdminAnnouncementService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * List all platform announcements for admin management.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function listAll(array $filters = []): array
    {
        $query = PlatformAnnouncement::query()->latest('id');

        if (! empty($filters['audience']) && in_array($filters['audience'], AnnouncementAudience::values(), true)) {
            $query->where('audience', $filters['audience']);
        }

        if (! empty($filters['priority']) && in_array($filters['priority'], AnnouncementPriority::values(), true)) {
            $query->where('priority', $filters['priority']);
        }

        if (isset($filters['active']) && $filters['active'] !== '') {
            $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->limit(200)
            ->get()
            ->map(fn (PlatformAnnouncement $a) => $a->toFrontendArray())
            ->values()
            ->all();
    }

    /**
     * Cached list of active announcements for the public/portal news ticker.
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicActive(?string $audience = null): array
    {
        $cleanAudience = ($audience && in_array($audience, AnnouncementAudience::values(), true))
            ? $audience
            : 'all';

        $cacheKey = $this->settingsService->cacheKey("public:announcements:{$cleanAudience}");

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($cleanAudience): array {
            $rows = PlatformAnnouncement::query()
                ->currentlyVisible($cleanAudience === 'all' ? null : $cleanAudience)
                ->latest('id')
                ->limit(25)
                ->get();

            return $rows
                ->sortByDesc(fn (PlatformAnnouncement $a) => $a->priority instanceof AnnouncementPriority ? $a->priority->weight() : 1)
                ->map(fn (PlatformAnnouncement $a) => $a->toFrontendArray())
                ->values()
                ->all();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $admin, array $data): array
    {
        $announcement = new PlatformAnnouncement([
            'title_ar' => $data['title_ar'],
            'title_en' => $data['title_en'] ?? $data['title_ar'],
            'content_ar' => $data['content_ar'],
            'content_en' => $data['content_en'] ?? $data['content_ar'],
            'audience' => $data['audience'] ?? AnnouncementAudience::ALL->value,
            'priority' => $data['priority'] ?? AnnouncementPriority::NORMAL->value,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        $announcement->forceFill(['created_by' => $admin->id])->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.announcement.created', [
            'admin_id' => $admin->id,
            'announcement_uuid' => $announcement->uuid,
            'audience' => $announcement->audience?->value,
            'priority' => $announcement->priority?->value,
        ], 'info', $announcement);

        return $announcement->toFrontendArray();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $admin, string $uuid, array $data): array
    {
        /** @var PlatformAnnouncement $announcement */
        $announcement = PlatformAnnouncement::query()->where('uuid', $uuid)->firstOrFail();

        $allowed = ['title_ar', 'title_en', 'content_ar', 'content_en', 'audience', 'priority', 'is_active', 'starts_at', 'expires_at'];
        $announcement->fill(array_intersect_key($data, array_flip($allowed)))->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.announcement.updated', [
            'admin_id' => $admin->id,
            'announcement_uuid' => $announcement->uuid,
        ], 'info', $announcement);

        return $announcement->fresh()->toFrontendArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleActive(User $admin, string $uuid): array
    {
        /** @var PlatformAnnouncement $announcement */
        $announcement = PlatformAnnouncement::query()->where('uuid', $uuid)->firstOrFail();

        $announcement->update(['is_active' => ! (bool) $announcement->is_active]);
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.announcement.toggled', [
            'admin_id' => $admin->id,
            'announcement_uuid' => $announcement->uuid,
            'is_active' => (bool) $announcement->is_active,
        ], 'info', $announcement);

        return $announcement->fresh()->toFrontendArray();
    }

    public function delete(User $admin, string $uuid): void
    {
        /** @var PlatformAnnouncement $announcement */
        $announcement = PlatformAnnouncement::query()->where('uuid', $uuid)->firstOrFail();

        $announcement->delete();
        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.announcement.deleted', [
            'admin_id' => $admin->id,
            'announcement_uuid' => $announcement->uuid,
        ], 'warning', $announcement);
    }
}
