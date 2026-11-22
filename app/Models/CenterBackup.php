<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CenterBackup extends Model
{
    use BelongsToCenter, HasFactory;

    protected $fillable = [
        'center_id',
        'triggered_by',
        'snapshot_code',
        'status',
        'checksum_sha256',
        'records_summary',
        'size_bytes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'records_summary' => 'array',
            'size_bytes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public static function generateSnapshotCode(): string
    {
        $date = now()->format('Ymd');

        do {
            $code = sprintf('BKP-%s-%s', $date, strtoupper(bin2hex(random_bytes(3))));
        } while (static::where('snapshot_code', $code)->exists());

        return $code;
    }
}
