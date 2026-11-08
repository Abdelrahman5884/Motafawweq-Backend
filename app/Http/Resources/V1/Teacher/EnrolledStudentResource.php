<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\AcademicStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class EnrolledStudentResource extends BaseJsonResource
{
    /**
     * Transform the enrolled student record into the exact structure used by RosterTable.jsx.
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

        $academic = $this->academic_status instanceof AcademicStatus
            ? $this->academic_status->value
            : (string) ($this->academic_status ?? 'Good');

        return [
            'id'               => $this->uuid,
            'studentId'        => $student?->id,
            'studentCode'      => $student?->student_code,
            'name'             => $nameEn,
            'nameAr'           => $nameAr,
            'phone'            => $phone,
            'parentName'       => $parentName,
            'parentNameAr'     => $parentName,
            'parentPhone'      => $parentPhone,
            'attendanceRate'   => (int) $this->attendance_rate,
            'attendedSessions' => (int) $this->attended_sessions,
            'totalSessions'    => (int) $this->total_sessions,
            'avgQuizScore'     => (int) $this->avg_quiz_score,
            'streakDays'       => (int) $this->streak_days,
            'status'           => $academic,
            'academicStatus'   => $academic,
            'studentBarcode'   => $this->student_barcode,
            'studentPasscode'  => $this->student_passcode,
            'avatar'           => $student?->avatar_url ?? 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?w=100&auto=format&fit=crop&q=80',
            'enrolledAt'       => $this->formatDate($this->enrolled_at),
        ];
    }
}

