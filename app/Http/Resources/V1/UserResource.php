<?php

namespace App\Http\Resources\V1;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class UserResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'role'          => $this->role?->value,
            'role_label'    => $this->role?->label(),
            'status'        => $this->status?->value,
            'status_label'  => $this->status?->label(),
            'phone'         => $this->phone,
            'avatar'        => $this->avatar,
            'last_login_at' => $this->formatDate($this->last_login_at),
            'created_at'    => $this->formatDate($this->created_at),
        ];
    }
}

