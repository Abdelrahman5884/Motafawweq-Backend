<?php

namespace App\Services\V1\Admin;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PlatformSettingsService extends BaseService
{
    public const CACHE_EPOCH_KEY = 'platform:cache_epoch';

    public const SETTINGS_CACHE_KEY = 'platform:settings:map:v1';

    /**
     * Authoritative schema of allowed platform settings (strictly whitelisted).
     *
     * @var array<string, array{default: mixed, type: string, group: string, camel: string, description_ar: string}>
     */
    public const DEFINITIONS = [
        'maintenance_mode' => [
            'default' => false,
            'type' => 'boolean',
            'group' => 'policies',
            'camel' => 'maintenanceMode',
            'description_ar' => 'تفعيل وضع الصيانة المؤقت للمنصة لغير المشرفين',
        ],
        'allow_teacher_registration' => [
            'default' => true,
            'type' => 'boolean',
            'group' => 'policies',
            'camel' => 'allowTeacherRegistration',
            'description_ar' => 'السماح بتسجيل المعلمين الجدد ذاتياً عبر صفحة التسجيل',
        ],
        'auto_certificates' => [
            'default' => true,
            'type' => 'boolean',
            'group' => 'policies',
            'camel' => 'autoCertificates',
            'description_ar' => 'إصدار الشهادات المعتمدة تلقائياً عند اجتياز الاختبار الشامل',
        ],
        'anti_passback' => [
            'default' => true,
            'type' => 'boolean',
            'group' => 'security',
            'camel' => 'antiPassback',
            'description_ar' => 'منع تكرار مسح الباركود في بوابات السنتر خلال المهلة المحددة',
        ],
        'anti_passback_minutes' => [
            'default' => 45,
            'type' => 'integer',
            'group' => 'security',
            'camel' => 'antiPassbackMinutes',
            'description_ar' => 'مدة الحماية ضد تكرار مسح الباركود بالدقائق',
        ],
        'high_performance_audio' => [
            'default' => true,
            'type' => 'boolean',
            'group' => 'ai',
            'camel' => 'highPerformanceAudio',
            'description_ar' => 'تفعيل معالجة الصوت فائقة السرعة على عناقيد GPU',
        ],
        'log_level' => [
            'default' => 'standard',
            'type' => 'string',
            'group' => 'security',
            'camel' => 'logLevel',
            'description_ar' => 'مستوى تسجيل وتدقيق الأحداث الأمنية',
        ],
        'platform_commission_percent' => [
            'default' => 20.0,
            'type' => 'float',
            'group' => 'general',
            'camel' => 'platformCommissionPercent',
            'description_ar' => 'نسبة عمولة المنصة القياسية من المبيعات',
        ],
        'ai_cost_per_minute_egp' => [
            'default' => 0.42,
            'type' => 'float',
            'group' => 'ai',
            'camel' => 'aiCostPerMinuteEgp',
            'description_ar' => 'متوسط تكلفة معالجة الدقيقة الصوتية بالذكاء الاصطناعي (ج.م)',
        ],
        'ai_revenue_per_minute_egp' => [
            'default' => 1.60,
            'type' => 'float',
            'group' => 'ai',
            'camel' => 'aiRevenuePerMinuteEgp',
            'description_ar' => 'متوسط العائد المحصل للدقيقة الصوتية بالذكاء الاصطناعي (ج.م)',
        ],
    ];

    /**
     * Get the current monotonic cache epoch used to namespace platform cache keys safely.
     */
    public function cacheEpoch(): int
    {
        return (int) Cache::get(self::CACHE_EPOCH_KEY, 1);
    }

    /**
     * Build an epoch-namespaced cache key so cache flushes never touch security/rate-limit buckets.
     */
    public function cacheKey(string $suffix): string
    {
        return sprintf('platform:e%d:%s', $this->cacheEpoch(), $suffix);
    }

    /**
     * Load all platform settings with defaults merged (cached).
     *
     * @return array<string, mixed>
     */
    public function allSnake(): array
    {
        return Cache::remember(self::SETTINGS_CACHE_KEY, now()->addMinutes(15), function (): array {
            $defaults = [];
            foreach (self::DEFINITIONS as $key => $def) {
                $defaults[$key] = $def['default'];
            }

            if (! Schema::hasTable('platform_settings')) {
                return $defaults;
            }

            $rows = PlatformSetting::query()->get(['key', 'value', 'value_type']);
            foreach ($rows as $row) {
                if (array_key_exists($row->key, self::DEFINITIONS)) {
                    $defaults[$row->key] = $row->typedValue();
                }
            }

            return $defaults;
        });
    }

    /**
     * Return settings formatted in camelCase for the Admin UI.
     *
     * @return array<string, mixed>
     */
    public function allFrontend(): array
    {
        $snake = $this->allSnake();
        $result = [];

        foreach (self::DEFINITIONS as $key => $def) {
            $result[$def['camel']] = $snake[$key];
        }

        return $result;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $all = $this->allSnake();

        return array_key_exists($key, $all) ? $all[$key] : $fallback;
    }

    public function isMaintenanceMode(): bool
    {
        return (bool) $this->get('maintenance_mode', false);
    }

    public function allowTeacherRegistration(): bool
    {
        return (bool) $this->get('allow_teacher_registration', true);
    }

    /**
     * Update whitelisted platform settings atomically and record an immutable audit trail diff.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function updateSettings(User $admin, array $validated): array
    {
        $before = $this->allSnake();
        $diff = [];

        $this->transaction(function () use ($admin, $validated, $before, &$diff): void {
            foreach ($validated as $key => $newValue) {
                if (! array_key_exists($key, self::DEFINITIONS)) {
                    continue;
                }

                $def = self::DEFINITIONS[$key];
                $casted = match ($def['type']) {
                    'boolean' => (bool) $newValue,
                    'integer' => (int) $newValue,
                    'float' => round((float) $newValue, 4),
                    default => (string) $newValue,
                };

                if (($before[$key] ?? null) !== $casted) {
                    $diff[$key] = [
                        'before' => $before[$key] ?? null,
                        'after' => $casted,
                    ];
                }

                $setting = PlatformSetting::query()->firstOrNew(['key' => $key]);
                $setting->fill([
                    'value' => PlatformSetting::encodeValue($casted, $def['type']),
                    'value_type' => $def['type'],
                    'group' => $def['group'],
                    'description_ar' => $def['description_ar'],
                ]);
                $setting->forceFill(['updated_by' => $admin->id])->save();
            }
        });

        Cache::forget(self::SETTINGS_CACHE_KEY);
        $this->bumpCacheEpoch();

        $level = array_key_exists('maintenance_mode', $diff)
            ? 'critical'
            : (array_intersect(array_keys($diff), ['allow_teacher_registration', 'anti_passback', 'log_level']) ? 'warning' : 'info');

        $this->logSecurityEvent('admin.system.settings_updated', [
            'admin_id' => $admin->id,
            'changes' => $diff,
        ], $level);

        return $this->allFrontend();
    }

    /**
     * Safely invalidate platform/public/admin application caches without wiping
     * security rate-limiters, OTP cooldowns, or idempotency locks.
     *
     * @return array<string, mixed>
     */
    public function flushApplicationCache(User $admin): array
    {
        Cache::forget(self::SETTINGS_CACHE_KEY);
        $newEpoch = $this->bumpCacheEpoch();

        $this->logSecurityEvent('admin.system.cache_flushed', [
            'admin_id' => $admin->id,
            'new_cache_epoch' => $newEpoch,
        ], 'warning');

        return [
            'flushed' => true,
            'cacheEpoch' => $newEpoch,
            'flushedAt' => now()->toIso8601String(),
        ];
    }

    public function bumpCacheEpoch(): int
    {
        $next = $this->cacheEpoch() + 1;
        Cache::forever(self::CACHE_EPOCH_KEY, $next);

        return $next;
    }
}
