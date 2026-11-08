<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class GroupAttendanceRecordResource extends BaseJsonResource
{
    /**
     * Transform the attendance record into a structured JSON payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof AttendanceStatus
            ? $this->status->value
            : (string) ($this->status ?? 'present');

        $method = $this->recorded_method instanceof AttendanceRecordMethod
            ? $this->recorded_method->value
            : (string) ($this->recorded_method ?? 'manual_toggle');

        $student = $this->student;

        return [
            'id'             => $this->uuid,
            'enrollmentId'   => $this->enrollment?->uuid,
            'studentId'      => $student?->id,
            'studentCode'    => $student?->student_code,
            'nameAr'         => $this->enrollment?->student_name_override ?: ($student?->name ?? 'طالب'),
            'status'         => $status,
            'statusAr'       => $this->status instanceof AttendanceStatus ? $this->status->labelAr() : $status,
            'recordedMethod' => $method,
            'scannedAt'      => $this->formatDate($this->scanned_at),
            'notes'          => $this->notes,
        ];
    }
}

