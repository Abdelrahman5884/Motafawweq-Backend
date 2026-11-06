<?php

namespace App\Services;

use App\Traits\HasSecurityAudit;
use Closure;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * @throws Exception
     */
    protected function transaction(Closure $callback): mixed
    {
        DB::beginTransaction();

        try {
            $result = $callback();
            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("[SERVICE-TRANSACTION-FAILED] " . $e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            throw $e;
        }
    }
}
