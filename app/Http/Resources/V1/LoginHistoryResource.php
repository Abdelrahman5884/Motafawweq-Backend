<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class LoginHistoryResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'status'     => $this->status?->value,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->formatDate($this->created_at),
        ];
    }
}

