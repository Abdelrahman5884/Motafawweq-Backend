<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\ExamSubmissionStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class TeacherExamSubmissionResource extends BaseJsonResource
{
    /**
     * Transform the submission summary for teacher's list view.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof ExamSubmissionStatus
            ? $this->status->value
            : (string) ($this->status ?? 'in_progress');

        $statusLabelAr = $this->status instanceof ExamSubmissionStatus
            ? $this->status->labelAr()
            : (ExamSubmissionStatus::tryFrom((string) $this->status)?->labelAr() ?? 'قيد الحل');

        $student = $this->whenLoaded('student');

        return [
            'id'              => $this->uuid,
            'status'          => $status,
            'statusLabelAr'   => $statusLabelAr,
            'score'           => (float) $this->score,
            'fullMark'        => (int) $this->full_mark,
            'percentage'      => (float) $this->percentage,
            'isPassed'        => (bool) $this->is_passed,
            'isFullMark'      => (bool) $this->is_full_mark,
            'teacherFeedback' => $this->teacher_feedback,
            'startedAt'       => $this->formatDate($this->started_at),
            'submittedAt'     => $this->formatDate($this->submitted_at),
            'gradedAt'        => $this->formatDate($this->graded_at),
            'student'         => $student ? [
                'id'          => $student->id,
                'name'        => $student->name,
                'email'       => $student->email,
                'studentCode' => $student->student_code ?? null,
                'avatar'      => $student->avatar,
            ] : null,
            'grader'          => $this->whenLoaded('grader', fn () => [
                'id'          => $this->grader?->id,
                'name'        => $this->grader?->name,
            ]),
        ];
    }
}

