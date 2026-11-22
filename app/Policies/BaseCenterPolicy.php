<?php

namespace App\Policies;

use App\Models\User;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Model;

abstract class BaseCenterPolicy
{
    use HasSecurityAudit;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isCenter()) {
            return false;
        }

        return null;
    }

    protected function ownsCenterResource(User $user, Model $resource): bool
    {
        $ownerId = $resource->getAttribute('center_id') ?? $resource->getAttribute('user_id');
        $isOwner = $ownerId !== null && (int) $ownerId === (int) $user->id;

        if (! $isOwner) {
            $this->logSecurityEvent(
                'center.policy_idor_blocked',
                [
                    'attempted_by_user_id' => $user->id,
                    'resource_owner_id' => $ownerId,
                    'resource_type' => get_class($resource),
                    'resource_uuid' => $resource->getAttribute('uuid') ?? $resource->getKey(),
                ],
                'warning',
                $resource
            );
        }

        return $isOwner;
    }
}
