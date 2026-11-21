<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\StudentPlanTier;
use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'starts_at',
        'renews_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'plan_tier' => StudentPlanTier::class,
            'billing_period' => BillingPeriod::class,
            'amount_egp' => 'float',
            'starts_at' => 'datetime',
            'renews_at' => 'datetime',
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
        return $this->hasMany(StudentInvoice::class, 'subscription_id');
    }
}
