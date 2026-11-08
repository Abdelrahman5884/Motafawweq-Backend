<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\RiskLevel;
use App\Enums\RiskSignalStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class StudentRiskSignalResource extends BaseJsonResource
{
    /**
     * Transform at-risk student row for TeacherAnalyticsView.jsx At-Risk Radar.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $student = $this->whenLoaded('student');
        $group = $this->whenLoaded('studyGroup');

        $level = $this->risk_level instanceof RiskLevel
            ? $this->risk_level->value
            : (string) ($this->risk_level ?? 'medium');

        $status = $this->status instanceof RiskSignalStatus
            ? $this->status->value
            : (string) ($this->status ?? 'detected');

        return [
            'id'            => $this->uuid,
            'studentId'     => $student ? (string) $student->id : null,
            'nameAr'        => $student?->name ?? 'طالب',
            'avatar'        => $student?->avatar,
            'gradeAr'       => $group?->grade_ar ?? 'الصف الثالث الثانوي',
            'groupAr'       => $group?->name_ar ?? 'عام',
            'centerName'    => $group?->center_name ?? 'السنتر',
            'attendancePct' => (int) $this->attendance_pct,
            'avgExamScore'  => (float) $this->avg_exam_score,
            'alertReasonAr' => $this->risk_reasons_ar,
            'riskLevel'     => $level,
            'status'        => $status,
            'statusLabelAr' => $this->status instanceof RiskSignalStatus ? $this->status->labelAr() : 'تم الرصد',
            'lastActiveAt'  => $this->formatDate($this->last_active_at),
            'createdAt'     => $this->formatDate($this->created_at),
        ];
    }
}

