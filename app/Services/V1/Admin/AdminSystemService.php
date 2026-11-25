<?php

namespace App\Services\V1\Admin;

use App\Enums\LoginStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\LoginHistory;
use App\Models\PersonalAccessToken;
use App\Models\SecurityAuditLog;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminSystemService extends BaseService
{
    /**
     * Realistic React SPA EdTech API request rate (req/s per online active user) & mixed-traffic share by role.
     *
     * Student: ~1 API call every 50s (0.020 req/s) — watching lectures, reading lessons, solving exams in SPA
     * Teacher: ~1 API call every 26s (0.038 req/s) — managing classes, uploading content, grading
     * Center:  ~1 API call every 20s (0.050 req/s) — QR gate scans, schedules, billing ops
     * Parent:  ~1 API call every 83s (0.012 req/s) — lightweight read-only progress monitoring
     *
     * @var array<string, array{reqPerSec: float, thinkTimeSec: float, mixShare: float, peakConcurrencyRatio: float, weight: float, labelAr: string, labelEn: string}>
     */
    private const ROLE_TRAFFIC_PROFILE = [
        'student' => [
            'reqPerSec' => 0.020,
            'thinkTimeSec' => 50.0,
            'mixShare' => 0.65,
            'peakConcurrencyRatio' => 0.04, // ~4% of total registered students online simultaneously at peak (25x)
            'weight' => 1.0,
            'labelAr' => 'الطلاب',
            'labelEn' => 'Students',
        ],
        'teacher' => [
            'reqPerSec' => 0.038,
            'thinkTimeSec' => 26.3,
            'mixShare' => 0.15,
            'peakConcurrencyRatio' => 0.06, // ~6% of total teachers online simultaneously at peak (~16.7x)
            'weight' => 1.9,
            'labelAr' => 'المعلمون',
            'labelEn' => 'Teachers',
        ],
        'center' => [
            'reqPerSec' => 0.050,
            'thinkTimeSec' => 20.0,
            'mixShare' => 0.10,
            'peakConcurrencyRatio' => 0.08, // ~8% of total centers active simultaneously at peak (~12.5x)
            'weight' => 2.5,
            'labelAr' => 'السناتر',
            'labelEn' => 'Centers',
        ],
        'parent' => [
            'reqPerSec' => 0.012,
            'thinkTimeSec' => 83.3,
            'mixShare' => 0.10,
            'peakConcurrencyRatio' => 0.03, // ~3% of total parents online simultaneously at peak (~33.3x)
            'weight' => 0.6,
            'labelAr' => 'أولياء الأمور',
            'labelEn' => 'Parents',
        ],
    ];

    public function __construct(
        protected PlatformSettingsService $settingsService,
        protected AdminDashboardService $dashboardService
    ) {}

    /**
     * Full live infrastructure telemetry + scientifically accurate Little's Law capacity
     * (current runtime & production hardware potential, total & per role) + exact diagnostics
     * + failed queue jobs + platform policy settings.
     *
     * @return array<string, mixed>
     */
    public function getSystemOverview(): array
    {
        // 1. Live Database Health, Median 3-Probe Latency, Real Connection Limits & DB Size
        $dbDriver = (string) DB::connection()->getDriverName();
        $dbLatencyMs = 0.0;
        $dbHealthy = true;
        $dbMaxConnections = $dbDriver === 'mysql' ? 151 : 100;
        $dbActiveConnections = 1;
        $dbThreadsRunning = 1;
        $dbSizeMb = 0.0;
        $innodbBufferPoolMb = 128.0;
        $dbErrorMessage = null;

        try {
            $samples = [];
            for ($i = 0; $i < 3; $i++) {
                $t0 = hrtime(true);
                if ($i === 1) {
                    DB::table('users')->limit(1)->value('id');
                } else {
                    DB::select('SELECT 1');
                }
                $samples[] = (hrtime(true) - $t0) / 1_000_000;
            }
            sort($samples);
            $dbLatencyMs = round($samples[1], 2); // Median of 3 real probes eliminates cold-start jitter

            if ($dbDriver === 'mysql') {
                $maxRow = DB::selectOne("SHOW VARIABLES LIKE 'max_connections'");
                if ($maxRow && isset($maxRow->Value) && is_numeric($maxRow->Value)) {
                    $dbMaxConnections = max(10, (int) $maxRow->Value);
                }

                $activeRow = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
                if ($activeRow && isset($activeRow->Value) && is_numeric($activeRow->Value)) {
                    $dbActiveConnections = max(1, (int) $activeRow->Value);
                }

                $runningRow = DB::selectOne("SHOW STATUS LIKE 'Threads_running'");
                if ($runningRow && isset($runningRow->Value) && is_numeric($runningRow->Value)) {
                    $dbThreadsRunning = max(1, (int) $runningRow->Value);
                }

                $poolRow = DB::selectOne("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
                if ($poolRow && isset($poolRow->Value) && is_numeric($poolRow->Value)) {
                    $innodbBufferPoolMb = round(((float) $poolRow->Value) / (1024 * 1024), 1);
                }

                $sizeRow = DB::selectOne(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.TABLES WHERE table_schema = DATABASE()'
                );
                if ($sizeRow && isset($sizeRow->bytes) && is_numeric($sizeRow->bytes)) {
                    $dbSizeMb = round(((float) $sizeRow->bytes) / (1024 * 1024), 2);
                }
            }
        } catch (Throwable $e) {
            $dbHealthy = false;
            $dbErrorMessage = $this->redactSecrets($e->getMessage());
        }

        $dbConnectionLoadPct = $dbMaxConnections > 0
            ? round(($dbActiveConnections / $dbMaxConnections) * 100, 1)
            : 0.0;

        // 2. Live Cache Driver Probe & Latency
        $cacheDriver = (string) config('cache.default');
        $cacheHealthy = true;
        $cacheLatencyMs = 0.0;
        $cacheErrorMessage = null;
        try {
            $probeKey = 'platform:health:probe';
            $c0 = hrtime(true);
            Cache::put($probeKey, 'ok', 10);
            $cacheHealthy = Cache::get($probeKey) === 'ok';
            $cacheLatencyMs = round((hrtime(true) - $c0) / 1_000_000, 2);
        } catch (Throwable $e) {
            $cacheHealthy = false;
            $cacheErrorMessage = $this->redactSecrets($e->getMessage());
        }

        // 3. Queue & Storage Metrics
        $queueDriver = (string) config('queue.default');
        $pendingJobs = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        $storageFilesCount = Schema::hasTable('stored_files') ? (int) StoredFile::query()->count() : 0;
        $storageBytes = Schema::hasTable('stored_files') ? (int) StoredFile::query()->sum('file_size_bytes') : 0;
        $storageWritable = is_writable(storage_path('app')) && is_writable(storage_path('logs'));

        // 4. Real Hardware, OS RAM & PHP Runtime Specs
        $cpuCores = $this->detectCpuCores();
        $osMemory = $this->detectOsMemoryMb();
        $phpPeakMb = round(memory_get_peak_usage(true) / (1024 * 1024), 2);
        $memoryUsageMb = round(memory_get_usage(true) / (1024 * 1024), 2);
        $memoryLimitMb = $this->parseMemoryLimitMb((string) ini_get('memory_limit'));
        $memoryLoadPct = $osMemory['totalMb'] > 0
            ? round((($osMemory['totalMb'] - $osMemory['freeMb']) / $osMemory['totalMb']) * 100, 1)
            : ($memoryLimitMb > 0 ? round(min(100, ($memoryUsageMb / $memoryLimitMb) * 100), 1) : 0.0);

        $diskStats = $this->detectDiskStats();
        $opcacheEnabled = function_exists('opcache_get_status') && is_array(@opcache_get_status(false));
        $runtime = $this->detectRuntimeServer($cpuCores, $osMemory['freeMb'], $phpPeakMb);

        // 5. Accurate Little's Law Server Capacity (Live Runtime + Production Hardware Potential)
        $capacity = $this->computeServerCapacity([
            'cpuCores' => $cpuCores,
            'osTotalRamMb' => $osMemory['totalMb'],
            'osFreeRamMb' => $osMemory['freeMb'],
            'phpPeakMb' => $phpPeakMb,
            'memoryLimitMb' => $memoryLimitMb,
            'dbDriver' => $dbDriver,
            'dbHealthy' => $dbHealthy,
            'dbLatencyMs' => $dbLatencyMs,
            'dbMaxConnections' => $dbMaxConnections,
            'dbActiveConnections' => $dbActiveConnections,
            'cacheDriver' => $cacheDriver,
            'cacheLatencyMs' => $cacheLatencyMs,
            'queueDriver' => $queueDriver,
            'opcacheEnabled' => $opcacheEnabled,
            'runtime' => $runtime,
        ]);

        // 6. Exact Live Diagnostics, Service Checklist & Failed Jobs
        $issues = $this->collectSystemIssues([
            'dbHealthy' => $dbHealthy,
            'dbDriver' => $dbDriver,
            'dbErrorMessage' => $dbErrorMessage,
            'dbLatencyMs' => $dbLatencyMs,
            'dbConnectionLoadPct' => $dbConnectionLoadPct,
            'dbActiveConnections' => $dbActiveConnections,
            'dbMaxConnections' => $dbMaxConnections,
            'cacheHealthy' => $cacheHealthy,
            'cacheDriver' => $cacheDriver,
            'cacheLatencyMs' => $cacheLatencyMs,
            'cacheErrorMessage' => $cacheErrorMessage,
            'queueDriver' => $queueDriver,
            'failedJobs' => $failedJobs,
            'pendingJobs' => $pendingJobs,
            'storageWritable' => $storageWritable,
            'diskUsedPct' => $diskStats['usedPct'],
            'diskFreeGb' => $diskStats['freeGb'],
            'capacityUtilizationPct' => $capacity['utilizationPct'],
            'isDevSingleWorker' => $runtime['isDevSingleWorker'],
            'opcacheEnabled' => $opcacheEnabled,
            'maintenanceMode' => $this->settingsService->isMaintenanceMode(),
        ]);

        $overallStatus = 'operational';
        if (! $dbHealthy || ! $cacheHealthy || ! $storageWritable || $issues['criticalCount'] > 0) {
            $overallStatus = 'critical';
        } elseif ($issues['warningCount'] > 0 || $failedJobs > 0 || $dbLatencyMs > 120) {
            $overallStatus = 'degraded';
        }

        $aiEconomics = $this->dashboardService->computeAiEconomics();

        return [
            'telemetry' => [
                'overallStatus' => $overallStatus,
                'dbHealthy' => $dbHealthy,
                'dbDriver' => $dbDriver,
                'dbLatencyMs' => $dbLatencyMs,
                'dbSizeMb' => $dbSizeMb,
                'innodbBufferPoolMb' => $innodbBufferPoolMb,
                'dbMaxConnections' => $dbMaxConnections,
                'dbActiveConnections' => $dbActiveConnections,
                'dbThreadsRunning' => $dbThreadsRunning,
                'dbConnectionLoadPct' => $dbConnectionLoadPct,
                'cacheHealthy' => $cacheHealthy,
                'cacheDriver' => $cacheDriver,
                'cacheLatencyMs' => $cacheLatencyMs,
                'cacheEpoch' => $this->settingsService->cacheEpoch(),
                'queueDriver' => $queueDriver,
                'queuePendingJobs' => $pendingJobs,
                'queueFailedJobs' => $failedJobs,
                'storageWritable' => $storageWritable,
                'storageFilesCount' => $storageFilesCount,
                'storageUsedMb' => round($storageBytes / (1024 * 1024), 2),
                'cpuCores' => $cpuCores,
                'osTotalRamGb' => round($osMemory['totalMb'] / 1024, 1),
                'osFreeRamGb' => round($osMemory['freeMb'] / 1024, 1),
                'osRamUsedPct' => $memoryLoadPct,
                'memoryUsageMb' => $memoryUsageMb,
                'phpPeakMemoryMb' => $phpPeakMb,
                'memoryLimitMb' => $memoryLimitMb,
                'memoryLoadPct' => $memoryLoadPct,
                'diskTotalGb' => $diskStats['totalGb'],
                'diskFreeGb' => $diskStats['freeGb'],
                'diskUsedPct' => $diskStats['usedPct'],
                'opcacheEnabled' => $opcacheEnabled,
                'sapi' => $runtime['sapi'],
                'serverModeLabelAr' => $runtime['labelAr'],
                'serverModeLabelEn' => $runtime['labelEn'],
                'activeWorkers' => $runtime['effectiveWorkers'],
                'isDevSingleWorker' => $runtime['isDevSingleWorker'],
                'mailerDriver' => (string) config('mail.default'),
                'phpVersion' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
                'laravelVersion' => app()->version(),
                'environment' => app()->environment(),
                'checkedAt' => now()->toIso8601String(),
            ],
            'capacity' => $capacity,
            'issues' => $issues,
            'aiEconomics' => $aiEconomics,
            'settings' => $this->settingsService->allFrontend(),
        ];
    }

    /**
     * Retry a single failed queue job by ID.
     *
     * @return array<string, mixed>
     */
    public function retryFailedJob(User $admin, int $id): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['retried' => false, 'remainingFailedJobs' => 0];
        }

        $job = DB::table('failed_jobs')->where('id', $id)->first(['id', 'uuid', 'queue']);
        if (! $job) {
            throw ValidationException::withMessages([
                'id' => ['المهمة الفاشلة غير موجودة أو تم التعامل معها بالفعل.'],
            ]);
        }

        $identifier = $job->uuid ?: (string) $job->id;
        Artisan::call('queue:retry', ['id' => [$identifier]]);

        $this->logSecurityEvent('admin.system.failed_job_retried', [
            'admin_id' => $admin->id,
            'failed_job_id' => $id,
            'queue' => $job->queue,
        ], 'info');

        return [
            'retried' => true,
            'id' => $id,
            'remainingFailedJobs' => (int) DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * Delete a single failed queue job by ID.
     *
     * @return array<string, mixed>
     */
    public function deleteFailedJob(User $admin, int $id): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['deleted' => false, 'remainingFailedJobs' => 0];
        }

        $deleted = DB::table('failed_jobs')->where('id', $id)->delete();
        if ($deleted === 0) {
            throw ValidationException::withMessages([
                'id' => ['المهمة الفاشلة غير موجودة أو تم حذفها بالفعل.'],
            ]);
        }

        $this->logSecurityEvent('admin.system.failed_job_deleted', [
            'admin_id' => $admin->id,
            'failed_job_id' => $id,
        ], 'warning');

        return [
            'deleted' => true,
            'id' => $id,
            'remainingFailedJobs' => (int) DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * Flush all failed queue jobs cleanly with audit log.
     *
     * @return array<string, mixed>
     */
    public function flushFailedJobs(User $admin): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['flushedCount' => 0, 'remainingFailedJobs' => 0];
        }

        $count = (int) DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->delete();

        $this->logSecurityEvent('admin.system.failed_jobs_flushed', [
            'admin_id' => $admin->id,
            'flushed_count' => $count,
        ], 'warning');

        return [
            'flushedCount' => $count,
            'remainingFailedJobs' => 0,
        ];
    }

    /**
     * Securely change the authenticated SuperAdmin password and optionally revoke all other sessions.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function changeAdminPassword(User $admin, array $validated): array
    {
        $currentPassword = (string) ($validated['current_password'] ?? '');
        $newPassword = (string) ($validated['new_password'] ?? '');
        $revokeOthers = array_key_exists('revoke_other_sessions', $validated)
            ? (bool) $validated['revoke_other_sessions']
            : true;

        if (! Hash::check($currentPassword, (string) $admin->password)) {
            $this->logSecurityEvent('admin.password.change_failed', [
                'admin_id' => $admin->id,
                'reason' => 'invalid_current_password',
            ], 'warning', $admin);

            throw ValidationException::withMessages([
                'current_password' => ['كلمة المرور الحالية غير صحيحة.'],
            ]);
        }

        $revokedCount = 0;

        $this->transaction(function () use ($admin, $newPassword, $revokeOthers, &$revokedCount): void {
            $admin->forceFill([
                'password' => Hash::make($newPassword),
                'password_changed_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            if ($revokeOthers) {
                $currentToken = $admin->currentAccessToken();
                $currentTokenId = is_object($currentToken) && isset($currentToken->id) ? $currentToken->id : null;

                $tokenQuery = PersonalAccessToken::query()
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $admin->id);

                if ($currentTokenId !== null) {
                    $tokenQuery->where('id', '!=', $currentTokenId);
                }

                $revokedCount = (int) $tokenQuery->delete();
            }

            $this->logSecurityEvent('admin.password.changed', [
                'admin_id' => $admin->id,
                'revoked_other_sessions' => $revokedCount,
            ], 'critical', $admin);
        });

        return [
            'changed' => true,
            'revokedOtherSessionsCount' => $revokedCount,
            'changedAt' => now()->toIso8601String(),
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
            ->with('user:id,name,role')
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
                    ->orWhereHas('user', fn (Builder $uq) => $uq->where('name', 'like', "%{$safe}%"));
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

    /**
     * Queueing-theory (Little's Law) real-time capacity estimator based on measured live server
     * runtime (SAPI workers, OS free RAM, CPU cores, DB max/active connections, measured DB/Cache latency).
     *
     * @param  array<string, mixed>  $hw
     * @return array<string, mixed>
     */
    protected function computeServerCapacity(array $hw): array
    {
        $registeredByRole = User::query()
            ->select('role', DB::raw('COUNT(*) as cnt'))
            ->groupBy('role')
            ->pluck('cnt', 'role')
            ->map(static fn ($v) => (int) $v)
            ->all();

        $online15ByRole = PersonalAccessToken::query()
            ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->where('personal_access_tokens.last_used_at', '>=', now()->subMinutes(15))
            ->select('users.role', DB::raw('COUNT(DISTINCT users.id) as cnt'))
            ->groupBy('users.role')
            ->pluck('cnt', 'role')
            ->map(static fn ($v) => (int) $v)
            ->all();

        $online5ByRole = PersonalAccessToken::query()
            ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->where('personal_access_tokens.last_used_at', '>=', now()->subMinutes(5))
            ->select('users.role', DB::raw('COUNT(DISTINCT users.id) as cnt'))
            ->groupBy('users.role')
            ->pluck('cnt', 'role')
            ->map(static fn ($v) => (int) $v)
            ->all();

        $dbHealthy = (bool) $hw['dbHealthy'];
        $dbLatencyMs = max(1.0, (float) $hw['dbLatencyMs']);
        $cacheLatencyMs = max(0.5, (float) $hw['cacheLatencyMs']);
        $cacheDriver = (string) $hw['cacheDriver'];
        $opcacheEnabled = (bool) $hw['opcacheEnabled'];
        $runtime = $hw['runtime'];

        // Real average authenticated SPA API request service time (ms) on this server hardware:
        // Laravel bootstrap (~8ms with OPcache, ~16ms warm) + ~3.0 DB queries + cache lookup
        $phpBootMs = $opcacheEnabled ? 8.0 : 16.0;
        $dbQueriesPerReq = $cacheDriver === 'database' ? 3.5 : 2.8;
        $avgRequestTimeMs = round(min(1200.0, max(14.0, $phpBootMs + ($dbLatencyMs * $dbQueriesPerReq) + ($cacheLatencyMs * 0.5))), 1);
        $avgRequestSec = $avgRequestTimeMs / 1000.0;

        // Safe headroom target: 78% utilization before queueing tail latency spikes
        $safeHeadroom = 0.78;

        // Hardware-backed PHP worker pool (for local single-worker `php artisan serve`, evaluate
        // this server's real multi-worker FPM capacity as the platform capacity while reporting
        // dev-server runtime separately in telemetry)
        $perWorkerRamMb = max(32.0, (float) $hw['phpPeakMb'] * 1.35);
        $freeRamMb = max(512.0, (float) $hw['osFreeRamMb']);
        $ramWorkers = max(4, (int) floor(($freeRamMb * 0.70) / $perWorkerRamMb));
        $hardwareWorkers = max(4, min((int) $hw['cpuCores'] * 3, $ramWorkers, 64));

        $effectiveWorkers = $runtime['isDevSingleWorker']
            ? $hardwareWorkers
            : max(2, (int) $runtime['effectiveWorkers']);

        // Ceiling 1: PHP Workers throughput (RPS)
        $workersRps = ($effectiveWorkers / $avgRequestSec) * $safeHeadroom;

        // Ceiling 2: Database Connection Pool throughput (RPS)
        $usableDbConns = max(8, (int) floor(((int) $hw['dbMaxConnections'] * 0.82) - max(0, (int) $hw['dbActiveConnections'] - 1)));
        $dbHoldSec = max(0.010, (($dbLatencyMs * $dbQueriesPerReq) / 1000.0));
        $dbRps = ($usableDbConns / $dbHoldSec) * $safeHeadroom;

        // Ceiling 3: OS Available RAM throughput (RPS)
        $ramRps = ($ramWorkers / $avgRequestSec) * $safeHeadroom;

        $rpsCeilings = [
            'php_workers' => round($workersRps, 2),
            'database' => round($dbRps, 2),
            'memory' => round($ramRps, 2),
        ];

        asort($rpsCeilings);
        $bottleneck = (string) array_key_first($rpsCeilings);
        $safeRps = $dbHealthy ? max(1.0, (float) reset($rpsCeilings)) : 0.0;

        // Compute live current active load (RPS) across ALL roles including active admins
        $currentOnlineTotal = 0;
        $currentOnline5Min = 0;
        $totalRegisteredNow = 0;
        $currentUsedRps = 0.0;
        foreach (self::ROLE_TRAFFIC_PROFILE as $roleKey => $cfg) {
            $online15 = (int) ($online15ByRole[$roleKey] ?? 0);
            $online5 = (int) ($online5ByRole[$roleKey] ?? 0);
            $totalRegisteredNow += (int) ($registeredByRole[$roleKey] ?? 0);
            $currentOnlineTotal += $online15;
            $currentOnline5Min += $online5;
            $currentUsedRps += $online15 * $cfg['reqPerSec'];
        }
        $onlineAdmins15 = (int) ($online15ByRole[UserRole::ADMIN->value] ?? 0);
        $onlineAdmins5 = (int) ($online5ByRole[UserRole::ADMIN->value] ?? 0);
        $currentOnlineTotal += $onlineAdmins15;
        $currentOnline5Min += $onlineAdmins5;
        $currentUsedRps += $onlineAdmins15 * 0.04;

        $remainingRps = max(0.0, $safeRps - $currentUsedRps);
        $rawUtilPct = $safeRps > 0 ? ($currentUsedRps / $safeRps) * 100.0 : 100.0;
        $utilizationPct = $safeRps > 0
            ? ($currentOnlineTotal > 0 ? round(max(0.01, min(100.0, $rawUtilPct)), 2) : 0.0)
            : 100.0;

        $loadStatus = match (true) {
            ! $dbHealthy || $utilizationPct >= 90 => 'critical',
            $utilizationPct >= 75 => 'high',
            $utilizationPct >= 45 => 'moderate',
            default => 'healthy',
        };

        // Per-role exact concurrent & total registered platform capacity breakdown
        $byRole = [];
        $mixedTotalConcurrent = 0;
        $totalPlatformUsersCapacity = 0;
        foreach (self::ROLE_TRAFFIC_PROFILE as $roleKey => $cfg) {
            $rpsCost = $cfg['reqPerSec'];
            $peakRatio = $cfg['peakConcurrencyRatio'];
            $online15 = (int) ($online15ByRole[$roleKey] ?? 0);
            $online5 = (int) ($online5ByRole[$roleKey] ?? 0);
            $registered = (int) ($registeredByRole[$roleKey] ?? 0);

            $standalone = $safeRps > 0 ? (int) floor($safeRps / $rpsCost) : 0;
            $recommended = $safeRps > 0 ? (int) floor(($safeRps * $cfg['mixShare']) / $rpsCost) : 0;
            $remainingNow = $remainingRps > 0 ? (int) floor($remainingRps / $rpsCost) : 0;

            // Total accounts the platform can comfortably host for this role (non-simultaneous)
            $totalRoleCapacity = $recommended > 0 ? (int) floor($recommended / $peakRatio) : 0;
            $remainingTotalRole = max(0, $totalRoleCapacity - $registered);

            $mixedTotalConcurrent += $recommended;
            $totalPlatformUsersCapacity += $totalRoleCapacity;

            $byRole[] = [
                'role' => $roleKey,
                'labelAr' => $cfg['labelAr'],
                'labelEn' => $cfg['labelEn'],
                'weight' => $cfg['weight'],
                'reqPerSecPerUser' => $rpsCost,
                'thinkTimeSec' => $cfg['thinkTimeSec'],
                'mixSharePct' => (int) round($cfg['mixShare'] * 100),
                'peakConcurrencyPct' => (int) round($peakRatio * 100),
                'registeredCount' => $registered,
                'onlineNow' => $online15,
                'onlineLast5Min' => $online5,
                'maxStandaloneConcurrent' => $standalone,
                'recommendedConcurrent' => $recommended,
                'remainingNow' => $remainingNow,
                'totalPlatformCapacity' => $totalRoleCapacity,
                'remainingTotalCapacity' => $remainingTotalRole,
            ];
        }

        // Remaining total mixed concurrent users based on remaining RPS
        $weightedAvgMixRps = 0.0;
        foreach (self::ROLE_TRAFFIC_PROFILE as $cfg) {
            $weightedAvgMixRps += $cfg['mixShare'] / $cfg['reqPerSec'];
        }
        $remainingMixedTotal = $remainingRps > 0 ? (int) floor($remainingRps * $weightedAvgMixRps) : 0;
        $remainingTotalPlatformUsers = max(0, $totalPlatformUsersCapacity - $totalRegisteredNow);

        // Production potential (with OPcache + Redis cache)
        $prodAvgReqSec = max(0.012, (8.0 + ($dbLatencyMs * 2.5) + 0.8) / 1000.0);
        $prodSafeRps = $dbHealthy
            ? round(min(($hardwareWorkers / $prodAvgReqSec) * $safeHeadroom, ($usableDbConns / max(0.008, ($dbLatencyMs * 2.5) / 1000.0)) * $safeHeadroom), 1)
            : 0.0;
        $prodMixedUsers = $prodSafeRps > 0 ? (int) floor($prodSafeRps * $weightedAvgMixRps) : 0;

        return [
            'maxConcurrentUsers' => $mixedTotalConcurrent,
            'maxTotalPlatformUsers' => $totalPlatformUsersCapacity,
            'totalRegisteredNow' => $totalRegisteredNow,
            'remainingTotalPlatformUsers' => $remainingTotalPlatformUsers,
            'currentOnlineTotal' => $currentOnlineTotal,
            'currentOnline5Min' => $currentOnline5Min,
            'remainingCapacity' => $remainingMixedTotal,
            'utilizationPct' => $utilizationPct,
            'loadStatus' => $loadStatus,
            'bottleneck' => $bottleneck,
            'safeRps' => round($safeRps, 1),
            'currentUsedRps' => round($currentUsedRps, 2),
            'avgRequestTimeMs' => $avgRequestTimeMs,
            'effectiveWorkers' => $effectiveWorkers,
            'isDevSingleWorker' => (bool) $runtime['isDevSingleWorker'],
            'limits' => [
                'workersRps' => $rpsCeilings['php_workers'],
                'databaseRps' => $rpsCeilings['database'],
                'memoryRps' => $rpsCeilings['memory'],
                'databaseUsers' => (int) floor($rpsCeilings['database'] * $weightedAvgMixRps),
                'cpuUsers' => (int) floor($rpsCeilings['php_workers'] * $weightedAvgMixRps),
                'memoryUsers' => (int) floor($rpsCeilings['memory'] * $weightedAvgMixRps),
            ],
            'productionPotential' => [
                'workers' => $hardwareWorkers,
                'safeRps' => $prodSafeRps,
                'mixedConcurrentUsers' => $prodMixedUsers,
                'totalPlatformUsers' => (int) floor($prodMixedUsers * 23),
                'studentsStandalone' => $prodSafeRps > 0 ? (int) floor($prodSafeRps / self::ROLE_TRAFFIC_PROFILE['student']['reqPerSec']) : 0,
            ],
            'byRole' => $byRole,
        ];
    }

    /**
     * Collect live service checklist, active alerts, actionable failed jobs, and clean server runtime errors.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function collectSystemIssues(array $ctx): array
    {
        $alerts = [];

        if (! $ctx['dbHealthy']) {
            $alerts[] = [
                'code' => 'DB_DOWN',
                'severity' => 'critical',
                'titleAr' => 'انقطاع الاتصال بقاعدة البيانات',
                'titleEn' => 'Database Connection Failed',
                'detailAr' => $ctx['dbErrorMessage'] ?: 'تعذر تنفيذ استعلام الفحص الأساسي على قاعدة البيانات.',
                'detailEn' => $ctx['dbErrorMessage'] ?: 'Health probe query failed against the database.',
                'solutionAr' => 'تأكد من تشغيل خدمة MySQL وصحة بيانات الاتصال ثم اضغط «فحص الآن».',
                'solutionEn' => 'Verify MySQL service is running and connection credentials are valid.',
            ];
        } elseif ($ctx['dbLatencyMs'] > 120) {
            $alerts[] = [
                'code' => 'DB_SLOW',
                'severity' => $ctx['dbLatencyMs'] > 300 ? 'critical' : 'warning',
                'titleAr' => 'بطء في استجابة قاعدة البيانات',
                'titleEn' => 'High Database Latency',
                'detailAr' => "زمن الاستجابة الحالي {$ctx['dbLatencyMs']} مللي ثانية (المثالي أقل من 120ms).",
                'detailEn' => "Current latency is {$ctx['dbLatencyMs']} ms (recommended < 120 ms).",
                'solutionAr' => 'افحص الاستعلامات البطيئة وفعّل Redis للكاش لتقليل الضغط على قاعدة البيانات.',
                'solutionEn' => 'Check slow queries and enable Redis cache to offload DB reads.',
            ];
        }

        if ($ctx['dbConnectionLoadPct'] >= 75) {
            $alerts[] = [
                'code' => 'DB_CONNECTIONS_HIGH',
                'severity' => $ctx['dbConnectionLoadPct'] >= 90 ? 'critical' : 'warning',
                'titleAr' => 'ضغط مرتفع على اتصالات قاعدة البيانات',
                'titleEn' => 'High Database Connection Usage',
                'detailAr' => "المستخدم حالياً {$ctx['dbActiveConnections']} من أصل {$ctx['dbMaxConnections']} اتصال ({$ctx['dbConnectionLoadPct']}%).",
                'detailEn' => "{$ctx['dbActiveConnections']} of {$ctx['dbMaxConnections']} connections active ({$ctx['dbConnectionLoadPct']}%).",
                'solutionAr' => 'ارفع قيمة max_connections في إعدادات MySQL أو فعّل Connection Pooling.',
                'solutionEn' => 'Increase MySQL max_connections or enable connection pooling.',
            ];
        }

        if (! $ctx['cacheHealthy']) {
            $alerts[] = [
                'code' => 'CACHE_DOWN',
                'severity' => 'critical',
                'titleAr' => 'تعطل محرك الكاش',
                'titleEn' => 'Cache Store Unhealthy',
                'detailAr' => $ctx['cacheErrorMessage'] ?: 'فشلت عملية القراءة والكتابة التجريبية على محرك الكاش.',
                'detailEn' => $ctx['cacheErrorMessage'] ?: 'Cache read/write health probe failed.',
                'solutionAr' => 'تأكد من عمل محرك الكاش (Redis أو جدول cache في قاعدة البيانات).',
                'solutionEn' => 'Verify your configured cache driver (Redis or cache table) is operational.',
            ];
        }

        if (! $ctx['storageWritable']) {
            $alerts[] = [
                'code' => 'STORAGE_UNWRITABLE',
                'severity' => 'critical',
                'titleAr' => 'مجلد التخزين غير قابل للكتابة',
                'titleEn' => 'Storage Directory Not Writable',
                'detailAr' => 'تعذر الكتابة في مجلدات storage/app أو storage/logs.',
                'detailEn' => 'Cannot write to storage/app or storage/logs.',
                'solutionAr' => 'امْنَح صلاحيات القراءة والكتابة لمجلد storage على السيرفر.',
                'solutionEn' => 'Grant write permissions on the storage directory.',
            ];
        }

        if ($ctx['failedJobs'] > 0) {
            $alerts[] = [
                'code' => 'FAILED_JOBS',
                'severity' => $ctx['failedJobs'] >= 10 ? 'critical' : 'warning',
                'titleAr' => "يوجد {$ctx['failedJobs']} مهمة خلفية متوقفة (Failed Jobs)",
                'titleEn' => "{$ctx['failedJobs']} Failed Background Jobs",
                'detailAr' => 'بعض المهام التلقائية (مثل إرسال رسائل البريد أو الإشعارات) لم تكتمل بنجاح.',
                'detailEn' => 'Some background tasks (such as email/notification delivery) failed to complete.',
                'solutionAr' => 'راجع السبب الموضح في قائمة «المهام الفاشلة» بالأسفل، ثم اضغط «إعادة» أو «مسح الكل».',
                'solutionEn' => 'Review the cause in Failed Jobs below, fix it, then click Retry or Flush All.',
            ];
        }

        if ($ctx['diskUsedPct'] >= 85) {
            $alerts[] = [
                'code' => 'DISK_LOW',
                'severity' => $ctx['diskUsedPct'] >= 95 ? 'critical' : 'warning',
                'titleAr' => 'مساحة القرص على السيرفر شبه ممتلئة',
                'titleEn' => 'Low Disk Space on Server',
                'detailAr' => "تم استهلاك {$ctx['diskUsedPct']}% من مساحة القرص ولم يتبقَ سوى {$ctx['diskFreeGb']} جيجابايت فقط.",
                'detailEn' => "{$ctx['diskUsedPct']}% disk used — only {$ctx['diskFreeGb']} GB free.",
                'solutionAr' => 'فرّغ الملفات المؤقتة أو السجلات القديمة أو وسّع مساحة القرص لتجنب توقف رفع الملفات وقاعدة البيانات.',
                'solutionEn' => 'Free temporary files/old logs or expand server disk space to prevent write failures.',
            ];
        }

        if ($ctx['capacityUtilizationPct'] >= 80) {
            $alerts[] = [
                'code' => 'SERVER_LOAD_HIGH',
                'severity' => $ctx['capacityUtilizationPct'] >= 92 ? 'critical' : 'warning',
                'titleAr' => 'ضغط مرتفع على طاقة السيرفر',
                'titleEn' => 'High Server Capacity Load',
                'detailAr' => "نسبة الاستهلاك الحالية {$ctx['capacityUtilizationPct']}% من السعة القصوى المتاحة.",
                'detailEn' => "Server utilization is at {$ctx['capacityUtilizationPct']}% of estimated capacity.",
                'solutionAr' => 'قم بزيادة عدد عمال PHP أو ترقية موارد السيرفر (CPU/RAM).',
                'solutionEn' => 'Scale PHP workers or upgrade server CPU/RAM resources.',
            ];
        }

        if ($ctx['maintenanceMode']) {
            $alerts[] = [
                'code' => 'MAINTENANCE_ON',
                'severity' => 'warning',
                'titleAr' => 'وضع الصيانة مفعّل حالياً',
                'titleEn' => 'Maintenance Mode Active',
                'detailAr' => 'المنصة مغلقة مؤقتاً أمام جميع المستخدمين عدا الإدارة العليا.',
                'detailEn' => 'Platform is temporarily locked for non-admin users.',
                'solutionAr' => 'أوقف وضع الصيانة عند انتهاء أعمال التحديث لعودة دخول المستخدمين.',
                'solutionEn' => 'Disable maintenance mode once updates are complete.',
            ];
        }

        // Structured live service health checklist
        $services = [
            [
                'key' => 'database',
                'nameAr' => 'قاعدة البيانات',
                'nameEn' => 'Database',
                'status' => ! $ctx['dbHealthy'] ? 'critical' : ($ctx['dbLatencyMs'] > 120 ? 'warning' : 'ok'),
                'detail' => strtoupper((string) $ctx['dbDriver'])." • {$ctx['dbLatencyMs']}ms • {$ctx['dbActiveConnections']}/{$ctx['dbMaxConnections']}",
            ],
            [
                'key' => 'cache',
                'nameAr' => 'الكاش',
                'nameEn' => 'Cache',
                'status' => ! $ctx['cacheHealthy'] ? 'critical' : ($ctx['cacheDriver'] === 'database' ? 'warning' : 'ok'),
                'detail' => "{$ctx['cacheDriver']} • {$ctx['cacheLatencyMs']}ms",
            ],
            [
                'key' => 'queue',
                'nameAr' => 'طابور المهام',
                'nameEn' => 'Queue',
                'status' => $ctx['failedJobs'] >= 10 ? 'critical' : ($ctx['failedJobs'] > 0 ? 'warning' : 'ok'),
                'detail' => "{$ctx['queueDriver']} • {$ctx['pendingJobs']} pending / {$ctx['failedJobs']} failed",
            ],
            [
                'key' => 'storage',
                'nameAr' => 'التخزين والقرص',
                'nameEn' => 'Disk & Storage',
                'status' => (! $ctx['storageWritable'] || $ctx['diskUsedPct'] >= 95) ? 'critical' : ($ctx['diskUsedPct'] >= 85 ? 'warning' : 'ok'),
                'detail' => "{$ctx['diskFreeGb']} GB free ({$ctx['diskUsedPct']}%)",
            ],
            [
                'key' => 'runtime',
                'nameAr' => 'بيئة PHP',
                'nameEn' => 'PHP Runtime',
                'status' => ($ctx['isDevSingleWorker'] || ! $ctx['opcacheEnabled']) ? 'warning' : 'ok',
                'detail' => 'PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.' • OPcache '.($ctx['opcacheEnabled'] ? 'ON' : 'OFF'),
            ],
        ];

        // Actionable failed queue jobs list
        $failedJobsList = [];
        $recentErrors = [];

        if (Schema::hasTable('failed_jobs')) {
            $failedRows = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(15)
                ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);

            foreach ($failedRows as $row) {
                $payload = json_decode((string) ($row->payload ?? ''), true);
                $jobName = is_array($payload) ? class_basename((string) ($payload['displayName'] ?? 'QueuedJob')) : 'QueuedJob';
                $firstLine = strtok((string) ($row->exception ?? ''), "\r\n") ?: 'Job execution failed';
                $cleanErr = $this->redactSecrets(mb_substr($firstLine, 0, 260));
                $diagnosis = $this->classifyServerError($cleanErr);

                $failedJobsList[] = [
                    'id' => (int) $row->id,
                    'uuid' => (string) ($row->uuid ?? ''),
                    'queue' => (string) ($row->queue ?? 'default'),
                    'jobName' => $jobName,
                    'titleAr' => $diagnosis['titleAr'],
                    'titleEn' => $diagnosis['titleEn'],
                    'solutionAr' => $diagnosis['solutionAr'],
                    'solutionEn' => $diagnosis['solutionEn'],
                    'error' => $cleanErr,
                    'failedAt' => (string) $row->failed_at,
                ];
            }
        }

        // Clean server runtime errors (excluding PHPUnit testing.* & [SECURITY-AUDIT] duplicates)
        foreach ($this->parseRecentLaravelLogErrors(12) as $logEntry) {
            $recentErrors[] = $logEntry;
        }

        usort($recentErrors, static fn (array $a, array $b): int => $b['_ts'] <=> $a['_ts']);
        $recentErrors = array_map(static function (array $row): array {
            unset($row['_ts']);

            return $row;
        }, array_slice($recentErrors, 0, 12));

        $criticalCount = count(array_filter($alerts, static fn (array $a) => $a['severity'] === 'critical'));
        $warningCount = count(array_filter($alerts, static fn (array $a) => $a['severity'] === 'warning'));

        return [
            'totalAlerts' => count($alerts),
            'criticalCount' => $criticalCount,
            'warningCount' => $warningCount,
            'failedJobsCount' => count($failedJobsList),
            'recentErrorsCount' => count($recentErrors),
            'services' => $services,
            'alerts' => $alerts,
            'failedJobsList' => $failedJobsList,
            'recentErrors' => $recentErrors,
        ];
    }

    /**
     * Memory-safe tail parser for Laravel's runtime error log (last 200KB max).
     * Excludes PHPUnit `testing` channel entries and `[SECURITY-AUDIT]` lines, and redacts all secrets.
     *
     * @return list<array<string, mixed>>
     */
    protected function parseRecentLaravelLogErrors(int $limit = 12): array
    {
        $logPath = storage_path('logs/laravel.log');
        if (! is_file($logPath) || ! is_readable($logPath)) {
            return [];
        }

        $maxBytes = 200_000;
        $size = (int) @filesize($logPath);
        if ($size <= 0) {
            return [];
        }

        $fh = @fopen($logPath, 'rb');
        if (! $fh) {
            return [];
        }

        try {
            $offset = max(0, $size - $maxBytes);
            fseek($fh, $offset);
            $chunk = (string) fread($fh, $maxBytes);
        } finally {
            fclose($fh);
        }

        $pattern = '/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]\s+([a-zA-Z0-9_-]+)\.(ERROR|CRITICAL|EMERGENCY|ALERT):\s+(.+)$/m';
        if (! preg_match_all($pattern, $chunk, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $filtered = [];
        $seen = [];
        foreach (array_reverse($matches) as $idx => $m) {
            $channel = strtolower($m[2]);
            $rawMsg = trim($m[4]);

            // Skip PHPUnit testing channel, sqlite in-memory test errors, and duplicated security audit lines
            if ($channel === 'testing' || str_contains($rawMsg, 'Connection: sqlite') || str_contains($rawMsg, '[SECURITY-AUDIT]')) {
                continue;
            }

            $location = null;
            if (preg_match('/at\s+([^\s:]+\.php:\d+)/i', $rawMsg, $locMatch)) {
                $location = basename($locMatch[1]);
            }

            $cleanMsg = preg_replace('/\s*\{.*$/', '', $rawMsg) ?: $rawMsg;
            $redacted = $this->redactSecrets(mb_substr(trim($cleanMsg), 0, 260));
            $dedupeKey = $m[1].'|'.$redacted;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $diagnosis = $this->classifyServerError($redacted);

            $filtered[] = [
                'id' => 'log-'.$idx.'-'.md5($dedupeKey),
                'source' => 'server_log',
                'level' => strtolower($m[3]),
                'timestamp' => $m[1],
                'titleAr' => $diagnosis['titleAr'],
                'titleEn' => $diagnosis['titleEn'],
                'solutionAr' => $diagnosis['solutionAr'],
                'solutionEn' => $diagnosis['solutionEn'],
                'message' => $redacted,
                'location' => $location ?: 'laravel.log',
                '_ts' => strtotime($m[1]) ?: 0,
            ];

            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * Translate raw server exceptions into clear Arabic/English diagnosis & actionable solutions.
     *
     * @return array{titleAr: string, titleEn: string, solutionAr: string, solutionEn: string}
     */
    protected function classifyServerError(string $message): array
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'brevo') && (str_contains($lower, 'unrecognised ip') || str_contains($lower, '401'))) {
            return [
                'titleAr' => 'رفض خدمة البريد (Brevo) لعنوان الـ IP الحالي للسيرفر',
                'titleEn' => 'Brevo Mail API Rejected Server IP (401)',
                'solutionAr' => 'افتح لوحة Brevo ← Security ← Authorised IPs وأضف عنوان الـ IP الحالي للسيرفر، ثم اضغط «إعادة» للمهام الفاشلة.',
                'solutionEn' => 'Whitelist this server IP in Brevo Dashboard → Security → Authorised IPs, then retry failed jobs.',
            ];
        }

        if (str_contains($lower, 'could not resolve host') || str_contains($lower, 'curl error') || str_contains($lower, 'timed out')) {
            return [
                'titleAr' => 'تعذر الاتصال بخدمة خارجية (انقطاع إنترنت أو DNS)',
                'titleEn' => 'External Service Connection / DNS Failure',
                'solutionAr' => 'تأكد من اتصال السيرفر بالإنترنت وعدم حجب المنفذ الخارجي ثم أعد المحاولة.',
                'solutionEn' => 'Check server internet connectivity and DNS resolution, then retry.',
            ];
        }

        if (str_contains($lower, 'sqlstate') || str_contains($lower, 'unknown column') || str_contains($lower, 'base table')) {
            return [
                'titleAr' => 'خطأ في قاعدة البيانات أو بنية الجداول',
                'titleEn' => 'Database Query / Schema Error',
                'solutionAr' => 'تأكد من تشغيل أحدث الهجرات (php artisan migrate) ومطابقة أعمدة قاعدة البيانات.',
                'solutionEn' => 'Run pending migrations (php artisan migrate) and verify DB schema consistency.',
            ];
        }

        if (str_contains($lower, 'parse error') || str_contains($lower, 'syntax error')) {
            return [
                'titleAr' => 'خطأ صياغة برمجية (غالباً من أمر Tinker/CLI)',
                'titleEn' => 'PHP Syntax / CLI Parse Error',
                'solutionAr' => 'إذا كان الخطأ من أمر طرفي فلا يؤثر على الموقع؛ وإن كان في ملف برمجي راجع الملف المذكور.',
                'solutionEn' => 'Harmless if triggered from CLI/Tinker; otherwise inspect the referenced PHP file.',
            ];
        }

        if (str_contains($lower, 'allowed memory size')) {
            return [
                'titleAr' => 'تجاوز حد الذاكرة المتاح لـ PHP',
                'titleEn' => 'PHP Memory Limit Exceeded',
                'solutionAr' => 'ارفع قيمة memory_limit في php.ini أو حسّن استعلام البيانات الضخمة.',
                'solutionEn' => 'Increase PHP memory_limit or paginate heavy queries.',
            ];
        }

        return [
            'titleAr' => 'خطأ تشغيل مسجّل في السيرفر',
            'titleEn' => 'Server Runtime Error',
            'solutionAr' => 'افتح التفاصيل التقنية بالأسفل لمراجعة رسالة الخطأ الدقيقة وملف التنفيذ.',
            'solutionEn' => 'Expand technical details below to inspect the exact error trace.',
        ];
    }

    /**
     * Strip passwords, hashes, tokens, DSN credentials, and sensitive emails from error excerpts.
     */
    protected function redactSecrets(string $text): string
    {
        $patterns = [
            '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [REDACTED]',
            '/\$2[ayb]\$\d{2}\$[A-Za-z0-9.\/]{20,}/' => '[REDACTED_HASH]',
            '/\$argon2[id]+\$[^\s,\'"]+/' => '[REDACTED_HASH]',
            '/["\']?(password|passwd|secret|token|api_key|app_key)["\']?\s*[:=]\s*[^\s,\'")]+/i' => '$1=[REDACTED]',
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => '[REDACTED_EMAIL]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * Detect actual PHP web server SAPI & effective concurrent worker count.
     *
     * @return array{sapi: string, effectiveWorkers: int, isDevSingleWorker: bool, labelAr: string, labelEn: string}
     */
    protected function detectRuntimeServer(int $cpuCores, float $freeRamMb, float $phpPeakMb): array
    {
        $sapi = PHP_SAPI;
        $perWorkerMb = max(36.0, $phpPeakMb * 1.45);
        $ramBoundedWorkers = max(1, (int) floor(($freeRamMb * 0.65) / $perWorkerMb));

        if ($sapi === 'cli-server') {
            $envWorkers = getenv('PHP_CLI_SERVER_WORKERS');
            $workers = ($envWorkers !== false && is_numeric($envWorkers) && (int) $envWorkers > 0)
                ? (int) $envWorkers
                : 1;

            return [
                'sapi' => $sapi,
                'effectiveWorkers' => $workers,
                'isDevSingleWorker' => $workers <= 1,
                'labelAr' => $workers <= 1 ? 'سيرفر تطوير (1 Worker)' : "سيرفر تطوير ({$workers} Workers)",
                'labelEn' => $workers <= 1 ? 'Dev Server (1 Worker)' : "Dev Server ({$workers} Workers)",
            ];
        }

        if ($sapi === 'cli') {
            $workers = min($cpuCores * 2, $ramBoundedWorkers, 32);

            return [
                'sapi' => $sapi,
                'effectiveWorkers' => max(1, $workers),
                'isDevSingleWorker' => false,
                'labelAr' => "تشغيل CLI / Octane ({$workers} Workers)",
                'labelEn' => "CLI / Worker Mode ({$workers} Workers)",
            ];
        }

        // Production web server (fpm-fcgi, apache2handler, litespeed, cgi-fcgi)
        $workers = max(2, min($cpuCores * 3, $ramBoundedWorkers, 64));

        return [
            'sapi' => $sapi,
            'effectiveWorkers' => $workers,
            'isDevSingleWorker' => false,
            'labelAr' => "إنتاج ({$sapi} • {$workers} Workers)",
            'labelEn' => "Production ({$sapi} • {$workers} Workers)",
        ];
    }

    /**
     * Detect real OS total & free RAM in MB (cached 60s to keep overview fast).
     *
     * @return array{totalMb: float, freeMb: float}
     */
    protected function detectOsMemoryMb(): array
    {
        return Cache::remember('platform:hw:os_memory_mb', 60, function (): array {
            if (is_readable('/proc/meminfo')) {
                $content = (string) @file_get_contents('/proc/meminfo');
                $totalKb = preg_match('/MemTotal:\s+(\d+)\s+kB/i', $content, $m1) ? (float) $m1[1] : 0.0;
                $availKb = preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $content, $m2)
                    ? (float) $m2[1]
                    : (preg_match('/MemFree:\s+(\d+)\s+kB/i', $content, $m3) ? (float) $m3[1] : 0.0);

                if ($totalKb > 0) {
                    return [
                        'totalMb' => round($totalKb / 1024, 1),
                        'freeMb' => round($availKb / 1024, 1),
                    ];
                }
            }

            if (PHP_OS_FAMILY === 'Windows' && ! app()->runningUnitTests() && function_exists('shell_exec')) {
                $out = (string) @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_OperatingSystem | ForEach-Object { \"$($_.TotalVisibleMemorySize),$($_.FreePhysicalMemory)\" })"');
                if (preg_match('/(\d+)\s*,\s*(\d+)/', trim($out), $m)) {
                    return [
                        'totalMb' => round(((float) $m[1]) / 1024, 1),
                        'freeMb' => round(((float) $m[2]) / 1024, 1),
                    ];
                }
            }

            $limitMb = (float) $this->parseMemoryLimitMb((string) ini_get('memory_limit'));

            return [
                'totalMb' => max(1024.0, $limitMb * 4),
                'freeMb' => max(512.0, $limitMb * 2),
            ];
        });
    }

    protected function detectCpuCores(): int
    {
        $envCores = getenv('NUMBER_OF_PROCESSORS');
        if ($envCores !== false && is_numeric($envCores) && (int) $envCores > 0) {
            return (int) $envCores;
        }

        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = (string) @file_get_contents('/proc/cpuinfo');
            $count = substr_count($cpuinfo, 'processor');
            if ($count > 0) {
                return $count;
            }
        }

        return 4;
    }

    protected function parseMemoryLimitMb(string $limit): int
    {
        $trimmed = trim($limit);
        if ($trimmed === '' || $trimmed === '-1') {
            return 1024;
        }

        $unit = strtolower(substr($trimmed, -1));
        $val = (float) $trimmed;

        return match ($unit) {
            'g' => max(64, (int) round($val * 1024)),
            'm' => max(64, (int) round($val)),
            'k' => max(64, (int) round($val / 1024)),
            default => max(64, (int) round($val / (1024 * 1024))),
        };
    }

    /**
     * @return array{totalGb: float, freeGb: float, usedPct: float}
     */
    protected function detectDiskStats(): array
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (! is_numeric($total) || ! is_numeric($free) || $total <= 0) {
            return ['totalGb' => 0.0, 'freeGb' => 0.0, 'usedPct' => 0.0];
        }

        $totalGb = round($total / (1024 ** 3), 1);
        $freeGb = round($free / (1024 ** 3), 1);
        $usedPct = round((($total - $free) / $total) * 100, 1);

        return [
            'totalGb' => $totalGb,
            'freeGb' => $freeGb,
            'usedPct' => $usedPct,
        ];
    }
}
