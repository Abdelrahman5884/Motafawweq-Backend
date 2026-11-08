<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TeacherInvoice extends Model
{
    use HasFactory, BelongsToTeacher;

    protected $fillable = [
        'invoice_number',
        'teacher_id',
        'subscription_id',
        'description_ar',
        'description_en',
        'base_amount_egp',
        'discount_amount_egp',
        'final_amount_egp',
        'payment_method',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_method'      => PaymentMethod::class,
            'status'              => InvoiceStatus::class,
            'base_amount_egp'     => 'float',
            'discount_amount_egp' => 'float',
            'final_amount_egp'    => 'float',
            'paid_at'             => 'datetime',
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
            $suffix = str_pad((string) random_int(100, 999), 3, '0', STR_PAD_LEFT);
            $inv = "T-INV-{$year}-{$suffix}";
        } while (static::where('invoice_number', $inv)->exists());

        return $inv;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TeacherSubscription::class, 'subscription_id');
    }
}

