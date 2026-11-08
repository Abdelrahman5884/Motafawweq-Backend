<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Immutable security & audit trail record.
 * Once created, audit logs cannot be modified or deleted via application code.
 */
class SecurityAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'user_id',
        'event',
        'level',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (empty($log->uuid)) {
                $log->uuid = (string) Str::uuid();
            }
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });

        static::updating(function () {
            throw new LogicException('Security audit logs are immutable and cannot be modified.');
        });

        static::deleting(function () {
            throw new LogicException('Security audit logs are immutable and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

