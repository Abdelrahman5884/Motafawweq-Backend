<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\HomeworkSubmissionStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TeacherHomeworkSubmissionResource extends BaseJsonResource
{
    /**
     * Transform the submission for TeacherHomeworkView.jsx grading drawer and submissions list.
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

        $student = $this->whenLoaded('student');

        $attachmentUrl = null;
        if ($this->attachment_path && Storage::disk('local')->exists($this->attachment_path)) {
            $attachmentUrl = url("/api/v1/teacher/homeworks/submissions/{$this->uuid}/attachment");
        }

        return [
            'id'                 => $this->uuid,
            'homeworkId'         => $this->whenLoaded('homework', fn () => $this->homework?->uuid),
            'studentId'          => $student ? (string) ($student->id) : null,
            'studentNameAr'      => $student?->name ?? 'طالب مجهول',
            'studentAvatar'      => $student?->avatar,
            'studentCode'        => $student?->student_code ?? null,
            'locationAr'         => $this->location_ar ?? 'أونلاين',
            'submittedAt'        => $this->formatDate($this->submitted_at),
            'type'               => $this->submission_type ?? 'file',
            'attachmentName'     => $this->attachment_name,
            'attachmentUrl'      => $attachmentUrl,
            'solutionText'       => $this->solution_text,
            'sampleSolutionText' => $this->solution_text,
            'status'             => $status,
            'statusLabelAr'      => $statusLabelAr,
            'score'              => $this->score !== null ? (float) $this->score : null,
            'maxScore'           => (int) $this->max_score,
            'feedbackAr'         => $this->feedback_ar ?? '',
            'gradedAt'           => $this->formatDate($this->graded_at),
            'grader'             => $this->whenLoaded('grader', fn () => [
                'id'   => $this->grader?->id,
                'name' => $this->grader?->name,
            ]),
        ];
    }
}

