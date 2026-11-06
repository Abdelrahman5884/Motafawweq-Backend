<?php

namespace App\Services;

use App\Traits\HasSecurityAudit;
use Closure;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class BaseService
{
    use HasSecurityAudit;

    /**
     * Execute a callback inside a safe database transaction.
     * Automatically rolls back on exception and logs.
     *
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     * @throws Throwable
     */
    protected function transaction(Closure $callback): mixed
    {
        DB::beginTransaction();

        try {
            $result = $callback();
            DB::commit();
            return $result;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("[SERVICE-TRANSACTION-FAILED] " . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * Send a notification without letting a mail/transport failure break the request.
     * Failures are logged so they can be investigated.
     */
    protected function safeNotify(object $notifiable, Notification $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (Throwable $e) {
            Log::error('[NOTIFICATION-FAILED] ' . get_class($notification), [
                'notifiable_id' => $notifiable->id ?? null,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
