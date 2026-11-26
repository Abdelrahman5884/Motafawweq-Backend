<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\StudentPlanTier;
use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StudentSubscription extends Model
{
    use BelongsToStudent, HasFactory;

    protected $fillable = [
        'uuid',
        'student_id',
        'plan_tier',
        'plan_name_ar',
        'plan_name_en',
        'billing_period',
        'amount_egp',
        'base_amount_egp',
        'discount_amount_egp',
        'coupon_code',
        'payment_method',
        'transaction_reference',
        'sender_reference',
        'receipt_path',
        'receipt_disk',
        'receipt_original_name',
        'receipt_mime_type',
        'is_free_promo',
        'starts_at',
        'renews_at',
        'cancelled_at',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'plan_tier' => StudentPlanTier::class,
            'billing_period' => BillingPeriod::class,
            'amount_egp' => 'float',
            'base_amount_egp' => 'float',
            'discount_amount_egp' => 'float',
            'is_free_promo' => 'boolean',
            'starts_at' => 'datetime',
            'renews_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(StudentInvoice::class, 'subscription_id');
    }
}
