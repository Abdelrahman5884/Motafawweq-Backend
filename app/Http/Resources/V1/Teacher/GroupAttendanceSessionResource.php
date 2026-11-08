<?php

namespace App\Http\Resources\V1\Teacher;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class GroupAttendanceSessionResource extends BaseJsonResource
{
    /**
     * Transform the attendance session into a structured JSON payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->uuid,
            'sessionDate'    => $this->session_date?->toDateString(),
            'titleAr'        => $this->title_ar,
            'status'         => $this->status,
            'notes'          => $this->notes,
            'totalEnrolled'  => (int) $this->total_enrolled,
            'presentCount'   => (int) $this->present_count,
            'absentCount'    => (int) $this->absent_count,
            'lateCount'      => (int) $this->late_count,
            'attendanceRate' => (float) $this->attendance_rate,
            'createdAt'      => $this->formatDate($this->created_at),
        ];
    }
}

