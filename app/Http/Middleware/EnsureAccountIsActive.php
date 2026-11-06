<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Exceptions\AuthSecurityException;
use App\Traits\HasSecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks the account status on EVERY request, so a suspension takes effect
 * immediately even for tokens that were issued before it.
 *
 * Usage:
 *   account.active          -> blocks suspended accounts
 *   account.active:strict   -> also blocks pending (not yet approved) accounts
 */
class EnsureAccountIsActive
{
    use HasSecurityAudit;

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if ($user && $user->status === UserStatus::SUSPENDED) {
            // Kill every token so the suspended user is fully logged out everywhere
            $user->tokens()->delete();
            $this->logSecurityEvent('SUSPENDED_USER_REQUEST_BLOCKED', ['user_id' => $user->id], 'warning');

            throw AuthSecurityException::accountSuspended();
        }

        if ($mode === 'strict' && $user && $user->status === UserStatus::PENDING) {
            throw AuthSecurityException::accountPending();
        }

        return $next($request);
    }
}

