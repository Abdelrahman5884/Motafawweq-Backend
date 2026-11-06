<?php

namespace App\Services\V1\Auth;

use App\Enums\TokenType;
use App\Exceptions\AuthSecurityException;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Issues and manages access/refresh token pairs.
 *
 * - Access token : short-lived, ability "access" + "role:{role}"
 * - Refresh token: long-lived, ability "refresh" only (cannot call the API)
 * - Both share a session_id = one logged-in device.
 * - Refresh rotates the pair (old pair is destroyed, so a refresh token is single-use).
 */
class TokenService extends BaseService
{
    public const ABILITY_ACCESS = 'access';
    public const ABILITY_REFRESH = 'refresh';

    /**
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, refresh_expires_in:int, session_id:string}
     */
    public function issuePair(User $user, ?string $ip, ?string $userAgent, ?string $sessionId = null): array
    {
        $sessionId ??= (string) Str::uuid();
        $accessTtl = config('auth_security.tokens.access_ttl_minutes');
        $refreshTtl = config('auth_security.tokens.refresh_ttl_minutes');

        if ($sessionId !== null) {
            $this->enforceSessionLimit($user, $sessionId);
        }

        $meta = [
            'session_id' => $sessionId,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? Str::limit($userAgent, 500, '') : null,
        ];

        $access = $user->createToken(
            'access',
            [self::ABILITY_ACCESS, "role:{$user->role->value}"],
            now()->addMinutes($accessTtl)
        );
        $access->accessToken->forceFill($meta + ['type' => TokenType::ACCESS])->save();

        $refresh = $user->createToken(
            'refresh',
            [self::ABILITY_REFRESH],
            now()->addMinutes($refreshTtl)
        );
        $refresh->accessToken->forceFill($meta + ['type' => TokenType::REFRESH])->save();

        return [
            'access_token'       => $access->plainTextToken,
            'refresh_token'      => $refresh->plainTextToken,
            'token_type'         => 'Bearer',
            'expires_in'         => $accessTtl * 60,
            'refresh_expires_in' => $refreshTtl * 60,
            'session_id'         => $sessionId,
        ];
    }

    /**
     * Rotate a refresh token: destroy the whole session pair and issue a fresh one.
     */
    public function rotate(User $user, PersonalAccessToken $refreshToken, ?string $ip, ?string $userAgent): array
    {
        return $this->transaction(function () use ($user, $refreshToken, $ip, $userAgent) {
            // Row lock: two concurrent refreshes with the same token -> only one wins
            $locked = PersonalAccessToken::whereKey($refreshToken->id)->lockForUpdate()->first();

            if (!$locked || !$locked->isRefresh() || ($locked->expires_at && $locked->expires_at->isPast())) {
                throw AuthSecurityException::invalidRefreshToken();
            }

            $sessionId = $locked->session_id ?? (string) Str::uuid();
            $this->revokeSession($user, $sessionId);

            return $this->issuePair($user, $ip, $userAgent, $sessionId);
        });
    }

    public function revokeSession(User $user, string $sessionId): int
    {
        return $user->tokens()->where('session_id', $sessionId)->delete();
    }

    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }

    public function revokeAllExcept(User $user, ?string $keepSessionId): int
    {
        return $user->tokens()
            ->when($keepSessionId, fn ($q) => $q->where(function ($q) use ($keepSessionId) {
                $q->where('session_id', '!=', $keepSessionId)->orWhereNull('session_id');
            }))
            ->delete();
    }

    /**
     * Active sessions (one per device), newest first.
     */
    public function listSessions(User $user): Collection
    {
        return $user->tokens()
            ->where('type', TokenType::REFRESH->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->get();
    }

    /**
     * Keep at most N concurrent sessions; drop the oldest ones.
     */
    protected function enforceSessionLimit(User $user, string $incomingSessionId): void
    {
        $max = (int) config('auth_security.tokens.max_sessions');

        $sessionIds = $user->tokens()
            ->where('type', TokenType::REFRESH->value)
            ->where('session_id', '!=', $incomingSessionId)
            ->orderByDesc('id')
            ->pluck('session_id')
            ->filter()
            ->unique()
            ->values();

        $overflow = $sessionIds->slice(max(0, $max - 1));

        if ($overflow->isNotEmpty()) {
            $user->tokens()->whereIn('session_id', $overflow->all())->delete();

            $this->logSecurityEvent('SESSION_LIMIT_EVICTION', [
                'user_id'         => $user->id,
                'evicted_session' => $overflow->count(),
            ]);
        }
    }
}

