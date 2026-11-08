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
    use HasFactory, BelongsToTeacher;

    protected $fillable = [
        'teacher_id',
        'plan_tier',
        'plan_name_ar',
        'plan_name_en',
        'billing_period',
        'amount_egp',
        'starts_at',
        'renews_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'plan_tier'      => TeacherPlanTier::class,
            'billing_period' => BillingPeriod::class,
            'amount_egp'     => 'float',
            'starts_at'      => 'datetime',
            'renews_at'      => 'datetime',
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

    public function invoices(): HasMany
    {
        return $this->hasMany(TeacherInvoice::class, 'subscription_id');
    }
}

