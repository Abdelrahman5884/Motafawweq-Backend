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
        $parentName = $this->parent_name_ar ?: '';
        $parentPhone = $this->parent_phone ?: '';

        $academic = $this->getAttribute('live_academic_status')
            ?: ($this->academic_status instanceof AcademicStatus
                ? $this->academic_status->value
                : (string) ($this->academic_status ?? AcademicStatus::GOOD->value));

        $academicEnum = AcademicStatus::tryFrom($academic) ?? AcademicStatus::GOOD;
        $liveAvgQuiz = $this->getAttribute('live_avg_quiz_score');
        $liveStreak = $this->getAttribute('live_streak_days');
        $gradedExamsCount = (int) ($this->getAttribute('live_graded_exams_count') ?? 0);

        return [
            'id' => $this->uuid,
            'studentId' => $student?->id,
            'studentCode' => $student?->student_code,
            'name' => $nameEn,
            'nameAr' => $nameAr,
            'email' => $student?->email,
            'phone' => $phone,
            'parentName' => $parentName,
            'parentNameAr' => $parentName,
            'parentPhone' => $parentPhone,
            'attendanceRate' => (int) $this->attendance_rate,
            'attendedSessions' => (int) $this->attended_sessions,
            'totalSessions' => (int) $this->total_sessions,
            'avgQuizScore' => $liveAvgQuiz !== null ? (int) $liveAvgQuiz : (int) $this->avg_quiz_score,
            'gradedExamsCount' => $gradedExamsCount,
            'streakDays' => $liveStreak !== null ? (int) $liveStreak : (int) $this->streak_days,
            'status' => $academicEnum->value,
            'academicStatus' => $academicEnum->value,
            'statusLabelAr' => $academicEnum->labelAr(),
            'studentBarcode' => $this->student_barcode,
            'studentPasscode' => $this->student_passcode,
            'todayAttendanceStatus' => $this->getAttribute('today_attendance_status'),
            'todayScannedAt' => $this->formatDate($this->getAttribute('today_scanned_at')),
            'avatar' => $student?->avatar ?: null,
            'enrolledAt' => $this->formatDate($this->enrolled_at),
        ];
    }
}
