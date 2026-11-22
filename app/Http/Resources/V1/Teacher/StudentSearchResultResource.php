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
        $profile = $this->relationLoaded('studentProfile') ? $this->studentProfile : null;

        return [
            'id' => $this->id,
            'studentCode' => $this->student_code,
            'name' => $this->name,
            'nameAr' => $this->name,
            'nameEn' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'parentNameAr' => $parent?->name ?? '',
            'parentPhone' => $parent?->phone ?? '',
            'gradeAr' => $profile?->grade_ar ?? '',
            'avatar' => $this->avatar ?: null,
        ];
    }
}
