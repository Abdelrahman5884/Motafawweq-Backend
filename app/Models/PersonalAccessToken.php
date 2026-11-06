<?php

namespace App\Models;

use App\Enums\TokenType;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Extended Sanctum token: carries token type (access/refresh),
 * a session id pairing both tokens of one device, and device metadata.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'type',
        'session_id',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => TokenType::class,
        ]);
    }

    public function isRefresh(): bool
    {
        return $this->type === TokenType::REFRESH;
    }
}

