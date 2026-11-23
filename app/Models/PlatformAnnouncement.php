<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PlatformAnnouncement extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title_ar',
        'title_en',
        'content_ar',
        'content_en',
        'audience',
        'priority',
        'is_active',
        'starts_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'audience' => AnnouncementAudience::class,
            'priority' => AnnouncementPriority::class,
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
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

    /**
     * Scope to active announcements within their scheduled visibility window.
     */
    public function scopeCurrentlyVisible(Builder $query, ?string $audience = null): Builder
    {
        $now = now();

        $query->where('is_active', true)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });

        if ($audience !== null && $audience !== '' && $audience !== 'all') {
            $query->whereIn('audience', [AnnouncementAudience::ALL->value, $audience]);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function toFrontendArray(): array
    {
        $audience = $this->audience instanceof AnnouncementAudience
            ? $this->audience
            : (AnnouncementAudience::tryFrom((string) $this->audience) ?? AnnouncementAudience::ALL);

        $priority = $this->priority instanceof AnnouncementPriority
            ? $this->priority
            : (AnnouncementPriority::tryFrom((string) $this->priority) ?? AnnouncementPriority::NORMAL);

        return [
            'id' => $this->uuid,
            'title' => $this->title_ar,
            'titleAr' => $this->title_ar,
            'titleEn' => $this->title_en ?: $this->title_ar,
            'content' => $this->content_ar,
            'contentAr' => $this->content_ar,
            'contentEn' => $this->content_en ?: $this->content_ar,
            'audience' => $audience->value,
            'audienceLabelAr' => $audience->labelAr(),
            'audienceLabelEn' => $audience->labelEn(),
            'priority' => $priority->value,
            'active' => (bool) $this->is_active,
            'isActive' => (bool) $this->is_active,
            'startsAt' => $this->starts_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'createdDate' => $this->created_at?->toDateString(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
