<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DiscountCoupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'discount_percent',
        'max_discount_egp',
        'min_order_egp',
        'is_active',
        'expires_at',
        'max_uses',
        'used_count',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'integer',
            'max_discount_egp' => 'float',
            'min_order_egp'    => 'float',
            'is_active'        => 'boolean',
            'expires_at'       => 'datetime',
            'max_uses'         => 'integer',
            'used_count'       => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            $model->code = strtoupper(trim((string) $model->code));
        });
    }

    public function isValid(float $orderAmount = 0.0): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        if ($this->min_order_egp > 0 && $orderAmount < $this->min_order_egp) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $orderAmount): float
    {
        if (!$this->isValid($orderAmount)) {
            return 0.00;
        }

        $discount = round(($orderAmount * ($this->discount_percent / 100)), 2);

        if ($this->max_discount_egp !== null && $discount > $this->max_discount_egp) {
            $discount = (float) $this->max_discount_egp;
        }

        return min($discount, $orderAmount);
    }
}

