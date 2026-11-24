<?php

namespace App\Services\V1\Admin;

use App\Enums\LoginStatus;
use App\Enums\UserStatus;
use App\Models\LoginHistory;
use App\Models\PersonalAccessToken;
use App\Models\SecurityAuditLog;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminSystemService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService,
        protected AdminDashboardService $dashboardService
    ) {}

    /**
     * Real infrastructure health telemetry + AI Unit Economics + Platform Policy Settings.
     *
     * @return array<string, mixed>
     */
    public function getSystemOverview(): array
    {
        // Measure live database response latency in ms
        $dbLatencyMs = 0.0;
        $dbHealthy = true;
        try {
            $t0 = hrtime(true);
            DB::select('SELECT 1');
            $dbLatencyMs = round((hrtime(true) - $t0) / 1_000_000, 2);
        } catch (Throwable) {
            $dbHealthy = false;
        }

        // Probe cache driver health
        $cacheHealthy = true;
        try {
            $probeKey = 'platform:health:probe';
            Cache::put($probeKey, 'ok', 10);
            $cacheHealthy = Cache::get($probeKey) === 'ok';
        } catch (Throwable) {
            $cacheHealthy = false;
        }

        $pendingJobs = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        $storageFilesCount = Schema::hasTable('stored_files') ? (int) StoredFile::query()->count() : 0;
        $storageBytes = Schema::hasTable('stored_files') ? (int) StoredFile::query()->sum('file_size_bytes') : 0;

        $aiEconomics = $this->dashboardService->computeAiEconomics();

        return [
            'telemetry' => [
                'overallStatus' => ($dbHealthy && $cacheHealthy) ? 'operational' : 'degraded',
                'dbHealthy' => $dbHealthy,
                'dbLatencyMs' => $dbLatencyMs,
                'cacheHealthy' => $cacheHealthy,
                'cacheDriver' => (string) config('cache.default'),
                'cacheEpoch' => $this->settingsService->cacheEpoch(),
                'queuePendingJobs' => $pendingJobs,
                'queueFailedJobs' => $failedJobs,
                'storageFilesCount' => $storageFilesCount,
                'storageUsedMb' => round($storageBytes / (1024 * 1024), 2),
                'memoryUsageMb' => round(memory_get_usage(true) / (1024 * 1024), 2),
                'phpVersion' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                'environment' => app()->environment(),
                'checkedAt' => now()->toIso8601String(),
            ],
            'aiEconomics' => $aiEconomics,
            'settings' => $this->settingsService->allFrontend(),
        ];
    }

    /**
     * Paginated global immutable security audit log viewer with rich filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listAuditLogs(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $level = isset($filters['level']) ? strtolower(trim((string) $filters['level'])) : null;
        $event = isset($filters['event']) ? trim((string) $filters['event']) : null;
        $userId = isset($filters['user_id']) && is_numeric($filters['user_id']) ? (int) $filters['user_id'] : null;
        $ip = isset($filters['ip_address']) ? trim((string) $filters['ip_address']) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $query = SecurityAuditLog::query()
            ->with('user:id,name,email,role')
            ->orderByDesc('id');

        if ($level && in_array($level, ['info', 'warning', 'critical', 'error'], true)) {
            $query->where('level', $level);
        }

        if ($event !== null && $event !== '') {
            $safeEvent = addcslashes($event, '%_\\');
            $query->where('event', 'like', "%{$safeEvent}%");
        }

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($ip !== null && $ip !== '') {
            $query->where('ip_address', $ip);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $query->where(function (Builder $q) use ($safe): void {
                $q->where('event', 'like', "%{$safe}%")
                    ->orWhere('ip_address', 'like', "%{$safe}%")
                    ->orWhereHas('user', fn (Builder $uq) => $uq->where('name', 'like', "%{$safe}%")->orWhere('email', 'like', "%{$safe}%"));
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        return [
            'items' => collect($paginator->items())->map(fn (SecurityAuditLog $log) => [
                'id' => $log->uuid,
                'event' => $log->event,
                'level' => $log->level,
                'actor' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                    'role' => $log->user->role?->value,
                ] : null,
                'auditableType' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'auditableId' => $log->auditable_id,
                'ipAddress' => $log->ip_address,
                'context' => $log->context,
                'createdAt' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
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
     * Global platform security threat & posture summary (last 24h).
     *
     * @return array<string, mixed>
     */
    public function getSecuritySummary(): array
    {
        $since = now()->subHours(24);

        $failedLogins24h = (int) LoginHistory::query()
            ->where('status', LoginStatus::FAILED->value)
            ->where('created_at', '>=', $since)
            ->count();

        $lockedAccountsNow = (int) User::query()
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->count();

        $suspendedAccountsCount = (int) User::query()
            ->where('status', UserStatus::SUSPENDED->value)
            ->count();

        $idorBlocked24h = (int) SecurityAuditLog::query()
            ->where('event', 'like', '%idor%')
            ->where('created_at', '>=', $since)
            ->count();

        $criticalEvents24h = (int) SecurityAuditLog::query()
            ->whereIn('level', ['critical', 'error'])
            ->where('created_at', '>=', $since)
            ->count();

        $activeSessionsCount = (int) PersonalAccessToken::query()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        return [
            'failedLogins24h' => $failedLogins24h,
            'lockedAccountsNow' => $lockedAccountsNow,
            'suspendedAccountsCount' => $suspendedAccountsCount,
            'idorAttemptsBlocked24h' => $idorBlocked24h,
            'criticalEvents24h' => $criticalEvents24h,
            'activeSessionsCount' => $activeSessionsCount,
            'maintenanceMode' => $this->settingsService->isMaintenanceMode(),
            'generatedAt' => now()->toIso8601String(),
        ];
    }
}
