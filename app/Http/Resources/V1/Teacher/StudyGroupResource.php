<?php

namespace App\Http\Resources\V1\Teacher;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class StudyGroupResource extends BaseJsonResource
{
    /**
     * Transform the study group into the frontend structure expected by ClassCard and ClassManager.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slots = $this->relationLoaded('scheduleSlots') ? $this->scheduleSlots : collect();

        return [
            'id'            => $this->uuid,
            'nameAr'        => $this->name_ar,
            'nameEn'        => $this->name_en ?? $this->name_ar,
            'subjectAr'     => $this->subject_ar,
            'subjectEn'     => $this->subject_en ?? $this->subject_ar,
            'gradeAr'       => $this->grade_ar,
            'gradeEn'       => $this->grade_en ?? $this->grade_ar,
            'centerName'    => $this->center_name,
            'hallName'      => $this->hall_name,
            'scheduleAr'    => $this->schedule_ar,
            'scheduleEn'    => $this->schedule_en ?? $this->schedule_ar,
            'priceEgp'      => (float) $this->price_egp,
            'maxStudents'   => (int) $this->max_students,
            'joinCode'      => $this->join_code,
            'qrCodeData'    => 'MOTAFAWWEQ:JOIN:' . $this->join_code,
            'enrolledCount' => (int) ($this->enrolled_count ?? 0),
            'pendingCount'  => (int) ($this->pending_count ?? 0),
            'status'        => $this->status,
            'scheduleSlots' => GroupScheduleSlotResource::collection($slots)->resolve($request),
            'createdAt'     => $this->formatDate($this->created_at),
            'updatedAt'     => $this->formatDate($this->updated_at),
        ];
    }
}

