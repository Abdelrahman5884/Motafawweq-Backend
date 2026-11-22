<?php

namespace App\Models;

use App\Enums\CenterPlanTier;
use App\Enums\CenterRefundPolicy;
use App\Traits\HasSecurityAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CenterProfile extends Model
{
    use HasFactory, HasSecurityAudit;

    /**
     * SECURITY: plan_tier and is_verified_center are intentionally NOT mass-assignable.
     * They may only be mutated via forceFill() inside CenterBillingService or Admin flows.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name_ar',
        'name_en',
        'phone',
        'address_ar',
        'address_en',
        'working_hours',
        'logo_url',
        'custom_domain',
        'late_margin_minutes',
        'payment_methods',
        'sibling_discount_percent',
        'mandatory_cash_close',
        'refund_policy',
        'default_teacher_settlement_rate',
        'target_attendance_pct',
        'target_monthly_revenue_egp',
        'target_room_utilization_pct',
        'preferred_theme',
        'preferred_lang',
        'last_backup_at',
        'last_backup_checksum',
    ];

    protected function casts(): array
    {
        return [
            'plan_tier' => CenterPlanTier::class,
            'is_verified_center' => 'boolean',
            'late_margin_minutes' => 'integer',
            'payment_methods' => 'array',
            'sibling_discount_percent' => 'integer',
            'mandatory_cash_close' => 'boolean',
            'refund_policy' => CenterRefundPolicy::class,
            'default_teacher_settlement_rate' => 'float',
            'target_attendance_pct' => 'integer',
            'target_monthly_revenue_egp' => 'float',
            'target_room_utilization_pct' => 'integer',
            'last_backup_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if ($model->payment_methods === null) {
                $model->payment_methods = [
                    'cash' => true,
                    'vodafoneCash' => true,
                    'instaPay' => true,
                    'visa' => true,
                ];
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOwnedByCenter(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }

    /**
     * @throws AuthorizationException
     */
    public function assertOwnedByCenter(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $user->isCenter() || ! $this->isOwnedByCenter($user)) {
            $this->logSecurityEvent('center.idor_attempt_blocked', [
                'attempted_by_user_id' => $user->id,
                'resource_owner_id' => $this->user_id,
                'resource_type' => static::class,
                'resource_uuid' => $this->uuid,
            ], 'warning', $this);

            throw new AuthorizationException(__('messages.center.forbidden_resource_owner'));
        }
    }
}
