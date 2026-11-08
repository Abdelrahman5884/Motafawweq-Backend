<?php

namespace App\Http\Resources\V1\Student;

use App\Enums\ExamSubmissionStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class StudentExamSubmissionResource extends BaseJsonResource
{
    /**
     * Transform the submission for the student.
     * Respects exam->show_results policy.
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

        $showResults = (bool) ($this->exam?->show_results ?? true);

        return [
            'id'              => $this->uuid,
            'examId'          => $this->whenLoaded('exam', fn () => $this->exam?->uuid),
            'examTitleAr'     => $this->whenLoaded('exam', fn () => $this->exam?->title_ar),
            'status'          => $status,
            'statusLabelAr'   => $statusLabelAr,
            'score'           => $showResults ? (float) $this->score : null,
            'fullMark'        => (int) $this->full_mark,
            'percentage'      => $showResults ? (float) $this->percentage : null,
            'isPassed'        => $showResults ? (bool) $this->is_passed : null,
            'isFullMark'      => $showResults ? (bool) $this->is_full_mark : null,
            'teacherFeedback' => $showResults ? $this->teacher_feedback : null,
            'startedAt'       => $this->formatDate($this->started_at),
            'submittedAt'     => $this->formatDate($this->submitted_at),
            'showResults'     => $showResults,
        ];
    }
}

