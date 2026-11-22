<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\CenterPlanTier;
use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CenterSubscription extends Model
{
    use BelongsToCenter, HasFactory;

    protected $fillable = [
        'center_id',
        'plan_tier',
        'plan_name_ar',
        'plan_name_en',
        'billing_period',
        'amount_egp',
        'starts_at',
        'renews_at',
        'cancelled_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'plan_tier' => CenterPlanTier::class,
            'billing_period' => BillingPeriod::class,
            'amount_egp' => 'float',
            'starts_at' => 'datetime',
            'renews_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CenterInvoice::class, 'subscription_id');
    }
}
