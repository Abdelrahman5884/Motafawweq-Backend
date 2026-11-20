<?php

namespace App\Models;

use App\Enums\LeagueDuration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RepublicLeague extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'republic_leagues';

    protected $fillable = [
        'uuid',
        'started_by',
        'title_ar',
        'duration_type',
        'duration_days',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_type' => LeagueDuration::class,
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function roundDaysRemaining(): int
    {
        if (! $this->is_active || ! $this->ends_at) {
            return 0;
        }

        $hours = Carbon::now()->diffInHours($this->ends_at, false);

        return $hours > 0 ? (int) ceil($hours / 24) : 0;
    }

    /**
     * Retrieve the currently active Admin-started Republic League (auto-closing if past ends_at).
     */
    public static function currentActive(): ?self
    {
        /** @var self|null $league */
        $league = static::query()
            ->where('is_active', true)
            ->latest('starts_at')
            ->first();

        if (! $league) {
            return null;
        }

        if ($league->ends_at && $league->ends_at->isPast()) {
            $league->update(['is_active' => false]);

            return null;
        }

        if ($league->starts_at && $league->starts_at->isFuture()) {
            return null;
        }

        return $league;
    }
}
