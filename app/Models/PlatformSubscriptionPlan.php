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

    /**
     * Overlay live database subscription plan names, badges, features, and prices onto a role's default catalog.
     * Falls back cleanly to $defaultCatalog when `platform_subscription_plans` has no rows for $targetRole.
     *
     * @param  array<int, array<string, mixed>>  $defaultCatalog
     * @return array<int, array<string, mixed>>
     */
    public static function overlayRoleCatalog(string $targetRole, array $defaultCatalog): array
    {
        $dbPlans = static::query()
            ->where('target_role', $targetRole)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get()
            ->keyBy('tier_key');

        if ($dbPlans->isEmpty()) {
            return $defaultCatalog;
        }

        return array_values(array_map(function (array $card) use ($dbPlans): array {
            $tier = (string) ($card['tier'] ?? '');
            /** @var self|null $live */
            $live = $dbPlans->get($tier);
            if (! $live) {
                return $card;
            }

            $monthly = (float) $live->price_egp;
            $term = $live->term_price_egp !== null ? (float) $live->term_price_egp : round($monthly * 4, 2);
            $annual = (float) $live->annual_price_egp;

            $card['nameAr'] = $live->name_ar ?: $card['nameAr'];
            $card['nameEn'] = $live->name_en ?: $card['nameEn'];
            $card['popular'] = (bool) $live->is_popular;

            if (is_array($live->features_ar) && ! empty($live->features_ar)) {
                $card['featuresAr'] = array_values($live->features_ar);
            }
            if (is_array($live->features_en) && ! empty($live->features_en)) {
                $card['featuresEn'] = array_values($live->features_en);
            }

            if (isset($card['pricing']['monthly'])) {
                $card['pricing']['monthly']['priceEgp'] = $monthly;
                if ($live->badge_ar) {
                    $card['pricing']['monthly']['badgeAr'] = $live->badge_ar;
                }
                if ($live->badge_en) {
                    $card['pricing']['monthly']['badgeEn'] = $live->badge_en;
                }
            }
            if (isset($card['pricing']['term'])) {
                $card['pricing']['term']['priceEgp'] = $term;
            }
            if (isset($card['pricing']['annual'])) {
                $card['pricing']['annual']['priceEgp'] = $annual;
            }

            return $card;
        }, $defaultCatalog));
    }
}
