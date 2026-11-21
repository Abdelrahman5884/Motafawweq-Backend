<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StudentInvoice extends Model
{
    use BelongsToStudent, HasFactory;

    protected $fillable = [
        'uuid',
        'invoice_number',
        'student_id',
        'subscription_id',
        'description_ar',
        'description_en',
        'base_amount_egp',
        'discount_amount_egp',
        'final_amount_egp',
        'coupon_code',
        'payment_method',
        'sender_reference',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'status' => InvoiceStatus::class,
            'base_amount_egp' => 'float',
            'discount_amount_egp' => 'float',
            'final_amount_egp' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->invoice_number)) {
                $model->invoice_number = static::generateUniqueInvoiceNumber();
            }
        });
    }

    public static function generateUniqueInvoiceNumber(): string
    {
        $year = date('Y');
        do {
            $suffix = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $number = "S-INV-{$year}-{$suffix}";
        } while (static::where('invoice_number', $number)->exists());

        return $number;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(StudentSubscription::class, 'subscription_id');
    }
}
