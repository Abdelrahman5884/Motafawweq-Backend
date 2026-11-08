<?php

namespace App\Http\Resources\V1\Student;

use App\Enums\HomeworkSubmissionStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudentHomeworkSubmissionResource extends BaseJsonResource
{
    /**
     * Transform the submission for the student.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof HomeworkSubmissionStatus
            ? $this->status->value
            : (string) ($this->status ?? 'pending');

        $statusLabelAr = $this->status instanceof HomeworkSubmissionStatus
            ? $this->status->labelAr()
            : (HomeworkSubmissionStatus::tryFrom((string) $this->status)?->labelAr() ?? 'قيد المراجعة');

        $attachmentUrl = null;
        if ($this->attachment_path && Storage::disk('local')->exists($this->attachment_path)) {
            $attachmentUrl = url("/api/v1/homeworks/submissions/{$this->uuid}/attachment");
        }

        return [
            'id'             => $this->uuid,
            'homeworkId'     => $this->whenLoaded('homework', fn () => $this->homework?->uuid),
            'status'         => $status,
            'statusLabelAr'  => $statusLabelAr,
            'submissionType' => $this->submission_type,
            'attachmentName' => $this->attachment_name,
            'attachmentUrl'  => $attachmentUrl,
            'solutionText'   => $this->solution_text,
            'score'          => $this->score !== null ? (float) $this->score : null,
            'maxScore'       => (int) $this->max_score,
            'feedbackAr'     => $this->feedback_ar,
            'submittedAt'    => $this->formatDate($this->submitted_at),
            'gradedAt'       => $this->formatDate($this->graded_at),
        ];
    }
}

