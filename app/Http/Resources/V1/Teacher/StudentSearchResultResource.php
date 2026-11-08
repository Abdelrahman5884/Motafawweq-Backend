<?php

namespace App\Http\Resources\V1\Teacher;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class StudentSearchResultResource extends BaseJsonResource
{
    /**
     * Transform a searchable student User into the payload for InviteStudentModal.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parent = $this->relationLoaded('parents') ? $this->parents->first() : null;

        return [
            'id'           => $this->id,
            'studentCode'  => $this->student_code,
            'name'         => $this->name,
            'nameAr'       => $this->name,
            'nameEn'       => $this->name,
            'phone'        => $this->phone,
            'email'        => $this->email,
            'parentNameAr' => $parent?->name ?? 'ولي الأمر',
            'parentPhone'  => $parent?->phone ?? $this->phone,
            'gradeAr'      => 'الصف الثالث الثانوي',
            'avatar'       => $this->avatar_url ?? 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=100&auto=format&fit=crop&q=80',
        ];
    }
}

