<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Enforces strict multi-tenant center ownership isolation and UUID routing.
 *
 * @mixin Model
 */
trait BelongsToCenter
{
    use HasSecurityAudit;

    public static function bootBelongsToCenter(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(User::class, 'center_id');
    }

    /**
     * Scope a query strictly to a specific center's owned records.
     */
    public function scopeForCenter(Builder $query, int|User $center): Builder
    {
        $centerId = $center instanceof User ? $center->id : $center;

        return $query->where($this->getTable().'.center_id', $centerId);
    }

    /**
     * Determine whether the given user owns this center resource.
     */
    public function isOwnedByCenter(int|User $center): bool
    {
        $centerId = $center instanceof User ? $center->id : $center;

        return (int) $this->center_id === (int) $centerId;
    }

    /**
     * Strictly assert ownership or throw an AuthorizationException and log an IDOR attempt.
     *
     * @throws AuthorizationException
     */
    public function assertOwnedByCenter(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $user->isCenter() || ! $this->isOwnedByCenter($user)) {
            $this->logSecurityEvent(
                'center.idor_attempt_blocked',
                [
                    'attempted_by_user_id' => $user->id,
                    'resource_owner_id' => $this->center_id,
                    'resource_type' => static::class,
                    'resource_uuid' => $this->uuid ?? $this->getKey(),
                ],
                'warning',
                $this
            );

            throw new AuthorizationException(__('messages.center.forbidden_resource_owner'));
        }
    }
}
