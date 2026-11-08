<?php

namespace App\Policies;

use App\Models\User;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Model;

abstract class BaseTeacherPolicy
{
    use HasSecurityAudit;

    /**
     * Admins have full oversight access.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin() && $user->isActive()) {
            return true;
        }

        return null;
    }

    /**
     * Ensure user is an active, verified teacher.
     */
    protected function isAuthorizedTeacher(User $user): bool
    {
        return $user->isTeacher() && $user->isActive() && $user->hasVerifiedEmail();
    }

    /**
     * Ensure user is an active, verified teacher AND owns the given model.
     */
    protected function ownsResource(User $user, Model $resource): bool
    {
        if (!$this->isAuthorizedTeacher($user)) {
            return false;
        }

        $owned = (int) ($resource->teacher_id ?? $resource->user_id ?? 0) === (int) $user->id;

        if (!$owned) {
            $this->logSecurityEvent(
                'teacher.policy_ownership_denied',
                [
                    'attempted_by_user_id' => $user->id,
                    'resource_owner_id'    => $resource->teacher_id ?? $resource->user_id ?? null,
                    'resource_class'       => get_class($resource),
                    'resource_key'         => $resource->uuid ?? $resource->getKey(),
                ],
                'warning',
                $resource
            );
        }

        return $owned;
    }

    public function viewAny(User $user): bool
    {
        return $this->isAuthorizedTeacher($user);
    }

    public function view(User $user, Model $resource): bool
    {
        return $this->ownsResource($user, $resource);
    }

    public function create(User $user): bool
    {
        return $this->isAuthorizedTeacher($user);
    }

    public function update(User $user, Model $resource): bool
    {
        return $this->ownsResource($user, $resource);
    }

    public function delete(User $user, Model $resource): bool
    {
        return $this->ownsResource($user, $resource);
    }
}

