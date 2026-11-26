<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
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
        'original_price_egp',
        'term_price_egp',
        'original_term_price_egp',
        'annual_price_egp',
        'original_annual_price_egp',
        'discount_percent',
        'commission_percent',
        'badge_ar',
        'badge_en',
        'is_popular',
        'is_active',
        'free_promo_enabled',
        'free_promo_until',
        'free_promo_label_ar',
        'free_promo_label_en',
        'features_ar',
        'features_en',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'price_egp' => 'float',
            'original_price_egp' => 'float',
            'term_price_egp' => 'float',
            'original_term_price_egp' => 'float',
            'annual_price_egp' => 'float',
            'original_annual_price_egp' => 'float',
            'discount_percent' => 'float',
            'commission_percent' => 'float',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
            'free_promo_enabled' => 'boolean',
            'free_promo_until' => 'datetime',
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
     * Whether this plan is currently covered by an active Super Admin temporary free promotion.
     */
    public function isCurrentlyFreePromo(): bool
    {
        if (! (bool) $this->free_promo_enabled) {
            return false;
        }

        if ($this->free_promo_until === null) {
            return true;
        }

        return $this->free_promo_until->isFuture();
    }

    /**
     * Compute effective discount percentage between an original (compare-at) price and current price.
     */
    public static function computeDiscountPercent(?float $originalPrice, float $currentPrice): ?float
    {
        if ($originalPrice === null || $originalPrice <= 0 || $currentPrice >= $originalPrice) {
            return null;
        }

        return round((($originalPrice - $currentPrice) / $originalPrice) * 100, 1);
    }

    /**
     * Compute effective price, compare-at (strikethrough) price, discount %, and free promo state for a billing period.
     *
     * @return array{
     *     priceEgp: float,
     *     originalPriceEgp: float|null,
     *     discountPercent: float|null,
     *     isFreePromo: bool,
     *     freePromoUntil: string|null,
     *     freePromoLabelAr: string|null,
     *     freePromoLabelEn: string|null
     * }
     */
    public function resolveCyclePricing(string $period = 'monthly'): array
    {
        $monthly = (float) $this->price_egp;
        $term = $this->term_price_egp !== null ? (float) $this->term_price_egp : round($monthly * 4, 2);
        $annual = (float) $this->annual_price_egp;

        [$regularPrice, $compareAtPrice] = match ($period) {
            'term' => [
                $term,
                $this->original_term_price_egp !== null ? (float) $this->original_term_price_egp : null,
            ],
            'annual' => [
                $annual,
                $this->original_annual_price_egp !== null ? (float) $this->original_annual_price_egp : null,
            ],
            default => [
                $monthly,
                $this->original_price_egp !== null ? (float) $this->original_price_egp : null,
            ],
        };

        $freePromoActive = $this->isCurrentlyFreePromo();
        if ($freePromoActive) {
            $struck = ($compareAtPrice !== null && $compareAtPrice > 0)
                ? $compareAtPrice
                : ($regularPrice > 0 ? $regularPrice : null);

            return [
                'priceEgp' => 0.0,
                'originalPriceEgp' => $struck,
                'discountPercent' => $struck !== null ? 100.0 : null,
                'isFreePromo' => true,
                'freePromoUntil' => $this->free_promo_until?->toIso8601String(),
                'freePromoLabelAr' => $this->free_promo_label_ar ?: 'مجانية لفترة محدودة',
                'freePromoLabelEn' => $this->free_promo_label_en ?: 'Free for a Limited Time',
            ];
        }

        $validCompare = ($compareAtPrice !== null && $compareAtPrice > $regularPrice) ? $compareAtPrice : null;
        $discountPct = self::computeDiscountPercent($validCompare, $regularPrice);

        return [
            'priceEgp' => max(0.0, round($regularPrice, 2)),
            'originalPriceEgp' => $validCompare !== null ? round($validCompare, 2) : null,
            'discountPercent' => $discountPct,
            'isFreePromo' => false,
            'freePromoUntil' => null,
            'freePromoLabelAr' => null,
            'freePromoLabelEn' => null,
        ];
    }

    /**
     * Authoritative server-side price resolver used during checkout across Student, Teacher, and Center roles.
     *
     * @return array{
     *     basePrice: float,
     *     originalPrice: float|null,
     *     discountPercent: float|null,
     *     isFreePromo: bool,
     *     freePromoUntil: Carbon|null,
     *     planNameAr: string|null,
     *     planNameEn: string|null
     * }
     */
    public static function resolveAuthoritativePrice(
        string $targetRole,
        string $tierKey,
        string $period,
        float $fallbackPrice
    ): array {
        /** @var self|null $live */
        $live = static::query()
            ->where('target_role', $targetRole)
            ->where('tier_key', $tierKey)
            ->where('is_active', true)
            ->first();

        if (! $live) {
            return [
                'basePrice' => max(0.0, round($fallbackPrice, 2)),
                'originalPrice' => null,
                'discountPercent' => null,
                'isFreePromo' => false,
                'freePromoUntil' => null,
                'planNameAr' => null,
                'planNameEn' => null,
            ];
        }

        $cycle = $live->resolveCyclePricing($period);

        return [
            'basePrice' => $cycle['priceEgp'],
            'originalPrice' => $cycle['originalPriceEgp'],
            'discountPercent' => $cycle['discountPercent'],
            'isFreePromo' => $cycle['isFreePromo'],
            'freePromoUntil' => $cycle['isFreePromo'] ? $live->free_promo_until : null,
            'planNameAr' => $live->name_ar ?: null,
            'planNameEn' => $live->name_en ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFrontendArray(): array
    {
        $monthlyCycle = $this->resolveCyclePricing('monthly');
        $termCycle = $this->resolveCyclePricing('term');
        $annualCycle = $this->resolveCyclePricing('annual');
        $freePromoActive = $this->isCurrentlyFreePromo();

        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'targetRole' => $this->target_role,
            'tierKey' => $this->tier_key,
            'nameAr' => $this->name_ar,
            'nameEn' => $this->name_en ?: $this->name_ar,
            'billingCycle' => $this->billing_cycle,
            'priceEgp' => (float) $this->price_egp,
            'originalPriceEgp' => $this->original_price_egp !== null ? (float) $this->original_price_egp : null,
            'termPriceEgp' => $this->term_price_egp !== null ? (float) $this->term_price_egp : null,
            'originalTermPriceEgp' => $this->original_term_price_egp !== null ? (float) $this->original_term_price_egp : null,
            'annualPriceEgp' => (float) $this->annual_price_egp,
            'originalAnnualPriceEgp' => $this->original_annual_price_egp !== null ? (float) $this->original_annual_price_egp : null,
            'discountPercent' => $this->discount_percent !== null
                ? (float) $this->discount_percent
                : self::computeDiscountPercent($this->original_price_egp, (float) $this->price_egp),
            'effectivePriceEgp' => $monthlyCycle['priceEgp'],
            'effectiveTermPriceEgp' => $termCycle['priceEgp'],
            'effectiveAnnualPriceEgp' => $annualCycle['priceEgp'],
            'commissionPercent' => $this->commission_percent !== null ? (float) $this->commission_percent : null,
            'badgeAr' => $this->badge_ar ?: '',
            'badgeEn' => $this->badge_en ?: ($this->badge_ar ?: ''),
            'isPopular' => (bool) $this->is_popular,
            'isActive' => (bool) $this->is_active,
            'freePromoEnabled' => (bool) $this->free_promo_enabled,
            'isFreePromoActive' => $freePromoActive,
            'freePromoUntil' => $this->free_promo_until?->toIso8601String(),
            'freePromoLabelAr' => $this->free_promo_label_ar ?: '',
            'freePromoLabelEn' => $this->free_promo_label_en ?: ($this->free_promo_label_ar ?: ''),
            'featuresAr' => is_array($this->features_ar) ? array_values($this->features_ar) : [],
            'featuresEn' => is_array($this->features_en) ? array_values($this->features_en) : [],
            'displayOrder' => (int) $this->display_order,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Overlay live database subscription plan names, badges, features, promotional discounts,
     * and temporary free promotions onto a role's default catalog.
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

            $freePromoActive = $live->isCurrentlyFreePromo();

            $card['nameAr'] = $live->name_ar ?: $card['nameAr'];
            $card['nameEn'] = $live->name_en ?: $card['nameEn'];
            $card['popular'] = (bool) $live->is_popular;
            $card['isFreePromo'] = $freePromoActive;
            $card['freePromoUntil'] = $freePromoActive ? $live->free_promo_until?->toIso8601String() : null;
            $card['freePromoLabelAr'] = $freePromoActive ? ($live->free_promo_label_ar ?: 'مجانية لفترة محدودة') : null;
            $card['freePromoLabelEn'] = $freePromoActive ? ($live->free_promo_label_en ?: 'Free for a Limited Time') : null;

            if (is_array($live->features_ar) && ! empty($live->features_ar)) {
                $card['featuresAr'] = array_values($live->features_ar);
            }
            if (is_array($live->features_en) && ! empty($live->features_en)) {
                $card['featuresEn'] = array_values($live->features_en);
            }

            foreach (['monthly', 'term', 'annual'] as $cycleKey) {
                if (! isset($card['pricing'][$cycleKey])) {
                    continue;
                }
                $resolved = $live->resolveCyclePricing($cycleKey);
                $card['pricing'][$cycleKey]['priceEgp'] = $resolved['priceEgp'];
                $card['pricing'][$cycleKey]['originalPriceEgp'] = $resolved['originalPriceEgp']
                    ?? ($cycleKey !== 'monthly' && ! $freePromoActive && $resolved['priceEgp'] > 0
                        ? ($card['pricing'][$cycleKey]['originalPriceEgp'] ?? null)
                        : null);

                // Ensure compare-at price is strictly greater than effective price
                if (
                    $card['pricing'][$cycleKey]['originalPriceEgp'] !== null
                    && $card['pricing'][$cycleKey]['originalPriceEgp'] <= $card['pricing'][$cycleKey]['priceEgp']
                ) {
                    $card['pricing'][$cycleKey]['originalPriceEgp'] = null;
                }

                $card['pricing'][$cycleKey]['discountPercent'] = $resolved['isFreePromo']
                    ? 100.0
                    : self::computeDiscountPercent(
                        $card['pricing'][$cycleKey]['originalPriceEgp'] !== null
                            ? (float) $card['pricing'][$cycleKey]['originalPriceEgp']
                            : null,
                        (float) $card['pricing'][$cycleKey]['priceEgp']
                    );
                $card['pricing'][$cycleKey]['isFreePromo'] = $resolved['isFreePromo'];
                $card['pricing'][$cycleKey]['freePromoUntil'] = $resolved['freePromoUntil'];
                $card['pricing'][$cycleKey]['freePromoLabelAr'] = $resolved['freePromoLabelAr'];
                $card['pricing'][$cycleKey]['freePromoLabelEn'] = $resolved['freePromoLabelEn'];

                if ($resolved['isFreePromo']) {
                    $card['pricing'][$cycleKey]['badgeAr'] = $resolved['freePromoLabelAr'];
                    $card['pricing'][$cycleKey]['badgeEn'] = $resolved['freePromoLabelEn'];
                } elseif ($cycleKey === 'monthly') {
                    if ($live->badge_ar) {
                        $card['pricing'][$cycleKey]['badgeAr'] = $live->badge_ar;
                    }
                    if ($live->badge_en) {
                        $card['pricing'][$cycleKey]['badgeEn'] = $live->badge_en;
                    }
                }
            }

            return $card;
        }, $defaultCatalog));
    }
}
