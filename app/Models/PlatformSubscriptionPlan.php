<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PlatformSubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'target_role',
        'tier_key',
        'name_ar',
        'name_en',
        'billing_cycle',
        'price_egp',
        'term_price_egp',
        'annual_price_egp',
        'commission_percent',
        'badge_ar',
        'badge_en',
        'is_popular',
        'is_active',
        'features_ar',
        'features_en',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'price_egp' => 'float',
            'term_price_egp' => 'float',
            'annual_price_egp' => 'float',
            'commission_percent' => 'float',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
            'features_ar' => 'array',
            'features_en' => 'array',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->slug)) {
                $model->slug = sprintf('plan-%s-%s', $model->target_role ?: 'custom', Str::lower(Str::random(8)));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toFrontendArray(): array
    {
        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'targetRole' => $this->target_role,
            'tierKey' => $this->tier_key,
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en ?: $this->name_ar,
            'billingCycle' => $this->billing_cycle,
            'priceEgp' => (float) $this->price_egp,
            'termPriceEgp' => $this->term_price_egp !== null ? (float) $this->term_price_egp : null,
            'annualPriceEgp' => (float) $this->annual_price_egp,
            'commissionPercent' => $this->commission_percent !== null ? (float) $this->commission_percent : null,
            'badgeAr' => $this->badge_ar ?: '',
            'badgeEn' => $this->badge_en ?: ($this->badge_ar ?: ''),
            'isPopular' => (bool) $this->is_popular,
            'isActive' => (bool) $this->is_active,
            'featuresAr' => is_array($this->features_ar) ? array_values($this->features_ar) : [],
            'featuresEn' => is_array($this->features_en) ? array_values($this->features_en) : [],
            'displayOrder' => (int) $this->display_order,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
