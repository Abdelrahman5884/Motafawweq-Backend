<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

/**
 * One logged-in device (represented by its refresh token row).
 */
class SessionResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $currentSession = $request->user()?->currentAccessToken()?->session_id;

        return [
            'id'          => $this->session_id,
            'ip_address'  => $this->ip_address,
            'user_agent'  => $this->user_agent,
            'is_current'  => $currentSession !== null && $currentSession === $this->session_id,
            'last_used_at'=> $this->formatDate($this->last_used_at),
            'created_at'  => $this->formatDate($this->created_at),
            'expires_at'  => $this->formatDate($this->expires_at),
        ];
    }
}

