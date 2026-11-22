<?php

namespace App\Models;

use App\Enums\CenterExpenseCategory;
use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CenterExpense extends Model
{
    use BelongsToCenter, HasFactory, SoftDeletes;

    protected $fillable = [
        'center_id',
        'branch_id',
        'receipt_number',
        'title_ar',
        'title_en',
        'category',
        'amount_egp',
        'expense_date',
        'status',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => CenterExpenseCategory::class,
            'amount_egp' => 'float',
            'expense_date' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CenterBranch::class, 'branch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function generateUniqueReceiptNumber(string $category = 'other'): string
    {
        $prefix = match ($category) {
            'salaries' => 'SAL',
            'maintenance' => 'MNT',
            'marketing' => 'MKT',
            default => 'REC',
        };
        $year = now()->format('Y');

        do {
            $number = sprintf('%s-%s-%06d', $prefix, $year, random_int(100000, 999999));
        } while (static::withTrashed()->where('receipt_number', $number)->exists());

        return $number;
    }
}
