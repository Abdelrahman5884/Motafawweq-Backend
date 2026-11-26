<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CenterInvoice extends Model
{
    use BelongsToCenter, HasFactory;

    protected $fillable = [
        'invoice_number',
        'center_id',
        'subscription_id',
        'description_ar',
        'description_en',
        'base_amount_egp',
        'discount_amount_egp',
        'final_amount_egp',
        'coupon_code',
        'payment_method',
        'sender_reference',
        'transaction_reference',
        'receipt_path',
        'status',
        'rejection_reason',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'base_amount_egp' => 'float',
            'discount_amount_egp' => 'float',
            'final_amount_egp' => 'float',
            'payment_method' => PaymentMethod::class,
            'status' => InvoiceStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CenterSubscription::class, 'subscription_id');
    }

    public static function generateUniqueInvoiceNumber(): string
    {
        $year = now()->format('Y');

        do {
            $number = sprintf('C-INV-%s-%06d', $year, random_int(100000, 999999));
        } while (static::where('invoice_number', $number)->exists());

        return $number;
    }
}
