<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait HasSecurityAudit
{
    /**
     * Log a security or audit event.
     *
     * @param  string  $event
     * @param  array  $context
     * @param  string  $level  info|warning|critical
     */
    public function logSecurityEvent(string $event, array $context = [], string $level = 'info'): void
    {
        $payload = array_merge([
            'event'      => $event,
            'ip'         => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'user_id'    => auth('sanctum')?->id() ?? auth()?->id(),
            'timestamp'  => now()->toIso8601String(),
        ], $context);

        Log::channel('single')->$level("[SECURITY-AUDIT] {$event}", $payload);
    }
}

