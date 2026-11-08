<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\JoinMethod;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class PendingStudentResource extends BaseJsonResource
{
    /**
     * Transform the pending student request into the structure used by PendingStudentsTable.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $student = $this->student;
        $nameAr = $this->student_name_override ?: ($student?->name ?? 'طالب متفوق');
        $nameEn = $student?->name ?: $nameAr;
        $phone = $this->student_phone_override ?: ($student?->phone ?? '');
        $parentName = $this->parent_name_ar ?: 'ولي الأمر';
        $parentPhone = $this->parent_phone ?: $phone;

        $methodEnum = $this->join_method instanceof JoinMethod
            ? $this->join_method
            : JoinMethod::tryFrom((string) $this->join_method);

        return [
            'id'           => $this->uuid,
            'studentId'    => $student?->id,
            'studentCode'  => $student?->student_code,
            'name'         => $nameEn,
            'nameAr'       => $nameAr,
            'phone'        => $phone,
            'parentNameAr' => $parentName,
            'parentPhone'  => $parentPhone,
            'method'       => $methodEnum ? $methodEnum->labelAr() : 'كود الانضمام',
            'joinMethod'   => $methodEnum ? $methodEnum->value : (string) $this->join_method,
            'requestedAt'  => $this->formatDate($this->requested_at ?? $this->created_at),
            'avatar'       => $student?->avatar_url ?? 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=100&auto=format&fit=crop&q=80',
        ];
    }
}

