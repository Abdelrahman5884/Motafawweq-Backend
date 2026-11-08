<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Enforces strict multi-tenant teacher ownership isolation and UUID routing.
 *
 * @mixin Model
 */
trait BelongsToTeacher
{
    use HasSecurityAudit;

    public static function bootBelongsToTeacher(): void
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Scope a query strictly to a specific teacher's owned records.
     */
    public function scopeForTeacher(Builder $query, int|User $teacher): Builder
    {
        $teacherId = $teacher instanceof User ? $teacher->id : $teacher;

        return $query->where($this->getTable() . '.teacher_id', $teacherId);
    }

    /**
     * Determine whether the given user owns this teacher resource.
     */
    public function isOwnedBy(int|User $teacher): bool
    {
        $teacherId = $teacher instanceof User ? $teacher->id : $teacher;

        return (int) $this->teacher_id === (int) $teacherId;
    }

    /**
     * Strictly assert ownership or throw an AuthorizationException and log an IDOR attempt.
     *
     * @throws AuthorizationException
     */
    public function assertOwnedBy(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (!$user->isTeacher() || !$this->isOwnedBy($user)) {
            $this->logSecurityEvent(
                'teacher.idor_attempt_blocked',
                [
                    'attempted_by_user_id' => $user->id,
                    'resource_owner_id'    => $this->teacher_id,
                    'resource_type'        => static::class,
                    'resource_uuid'        => $this->uuid ?? $this->getKey(),
                ],
                'warning',
                $this
            );

            throw new AuthorizationException(__('messages.forbidden_resource_owner'));
        }
    }
}

