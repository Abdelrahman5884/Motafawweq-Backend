<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\TeacherPlanTier;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TeacherSubscription extends Model
{
    use BelongsToTeacher, HasFactory;

    protected $fillable = [
        'teacher_id',
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
            'plan_tier' => TeacherPlanTier::class,
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(TeacherInvoice::class, 'subscription_id');
    }
}
