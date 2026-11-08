<?php

namespace App\Traits;

use App\Models\SecurityAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

trait HasSecurityAudit
{
    /**
     * Log a security or audit event to both the log file and the immutable database audit table.
     *
     * @param  string       $event
     * @param  array        $context
     * @param  string       $level      info|warning|critical
     * @param  Model|null   $auditable  Optional Eloquent model being acted upon
     */
    public function logSecurityEvent(
        string $event,
        array $context = [],
        string $level = 'info',
        ?Model $auditable = null
    ): void {
        $userId = auth('sanctum')?->id() ?? auth()?->id() ?? ($context['user_id'] ?? null);
        $ip = request()?->ip();
        $userAgent = request()?->userAgent();

        $payload = array_merge([
            'event'      => $event,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'user_id'    => $userId,
            'timestamp'  => now()->toIso8601String(),
        ], $context);

        $validLevel = in_array($level, ['info', 'warning', 'critical', 'error'], true) ? $level : 'info';
        Log::channel('single')->$validLevel("[SECURITY-AUDIT] {$event}", $payload);

        try {
            if (Schema::hasTable('security_audit_logs')) {
                SecurityAuditLog::query()->create([
                    'user_id'        => is_numeric($userId) ? (int) $userId : null,
                    'event'          => substr($event, 0, 120),
                    'level'          => substr($validLevel, 0, 20),
                    'auditable_type' => $auditable ? get_class($auditable) : ($context['auditable_type'] ?? null),
                    'auditable_id'   => $auditable ? (string) ($auditable->uuid ?? $auditable->getKey()) : ($context['auditable_id'] ?? null),
                    'ip_address'     => $ip ? substr($ip, 0, 45) : null,
                    'user_agent'     => $userAgent,
                    'context'        => $context,
                ]);
            }
        } catch (Throwable) {
            // Never allow audit DB persistence failure (e.g. during an aborted transaction) to break the request flow
        }
    }
}
