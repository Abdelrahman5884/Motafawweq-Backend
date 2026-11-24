<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PlatformExpense extends Model
{
    use SoftDeletes;

    protected $table = 'platform_expenses';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'category',
        'amount_egp',
        'note',
        'expense_date',
    ];

    protected function casts(): array
    {
        return [
            'amount_egp' => 'float',
            'expense_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toFrontendArray(): array
    {
        return [
            'id' => $this->uuid,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'category' => $this->category ?: 'operations',
            'amountEgp' => round((float) $this->amount_egp, 2),
            'note' => $this->note ?: '',
            'expenseDate' => $this->expense_date?->toDateString() ?? $this->created_at?->toDateString(),
            'createdByName' => $this->creator?->name,
            'updatedByName' => $this->updater?->name,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
