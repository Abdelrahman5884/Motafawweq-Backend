<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Append-only, immutable student XP ledger.
 * Points can ONLY be awarded by server events and can never be updated or deleted.
 */
class StudentXpLedger extends Model
{
    use BelongsToStudent, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid',
        'student_id',
        'source_type',
        'source_id',
        'points',
        'reason_ar',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'points' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });

        static::updating(function () {
            throw new LogicException('Student XP ledger entries are immutable and cannot be modified.');
        });

        static::deleting(function () {
            throw new LogicException('Student XP ledger entries are immutable and cannot be deleted.');
        });
    }

    /**
     * Award XP idempotently for a unique (student_id, source_type, source_id) tuple.
     */
    public static function awardOnce(int $studentId, string $sourceType, int $sourceId, int $points, string $reasonAr): ?self
    {
        if ($points <= 0) {
            return null;
        }

        return static::firstOrCreate(
            [
                'student_id' => $studentId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            [
                'points' => $points,
                'reason_ar' => $reasonAr,
            ]
        );
    }
}
