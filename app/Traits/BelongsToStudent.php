<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Enforces strict multi-tenant student ownership isolation and UUID routing.
 *
 * @mixin Model
 */
trait BelongsToStudent
{
    use HasSecurityAudit;

    public static function bootBelongsToStudent(): void
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Scope a query strictly to a specific student's owned records.
     */
    public function scopeForStudent(Builder $query, int|User $student): Builder
    {
        $studentId = $student instanceof User ? $student->id : $student;

        return $query->where($this->getTable().'.student_id', $studentId);
    }

    /**
     * Determine whether the given user owns this student resource.
     */
    public function isOwnedByStudent(int|User $student): bool
    {
        $studentId = $student instanceof User ? $student->id : $student;

        return (int) $this->student_id === (int) $studentId;
    }

    /**
     * Strictly assert student ownership or throw an AuthorizationException and log an IDOR attempt.
     *
     * @throws AuthorizationException
     */
    public function assertOwnedByStudent(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $user->isStudent() || ! $this->isOwnedByStudent($user)) {
            $this->logSecurityEvent(
                'student.idor_attempt_blocked',
                [
                    'attempted_by_user_id' => $user->id,
                    'resource_owner_id' => $this->student_id,
                    'resource_type' => static::class,
                    'resource_uuid' => $this->uuid ?? $this->getKey(),
                ],
                'warning',
                $this
            );

            throw new AuthorizationException(__('messages.forbidden_resource_owner'));
        }
    }
}
