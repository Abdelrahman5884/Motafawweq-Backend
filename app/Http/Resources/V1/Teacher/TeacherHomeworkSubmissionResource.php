<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\HomeworkSubmissionStatus;
use App\Http\Resources\BaseJsonResource;
use App\Services\V1\Storage\HomeworkAttachmentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class TeacherHomeworkSubmissionResource extends BaseJsonResource
{
    /**
     * Transform the submission for TeacherHomeworkView.jsx grading desk and submissions list.
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
        $attachmentDownloadUrl = null;
        if (! empty($this->attachment_path)) {
            if (Route::has('api.v1.teacher.homeworks.submission.attachment.stream')) {
                $expiry = now()->addHours(2);
                $attachmentUrl = URL::temporarySignedRoute(
                    'api.v1.teacher.homeworks.submission.attachment.stream',
                    $expiry,
                    ['submissionUuid' => $this->uuid]
                );
                $attachmentDownloadUrl = URL::temporarySignedRoute(
                    'api.v1.teacher.homeworks.submission.attachment.stream',
                    $expiry,
                    ['submissionUuid' => $this->uuid, 'download' => 1]
                );
            } else {
                $attachmentUrl = url("/api/v1/teacher/homeworks/submissions/{$this->uuid}/attachment");
                $attachmentDownloadUrl = url("/api/v1/teacher/homeworks/submissions/{$this->uuid}/attachment?download=1");
            }
        }

        $sizeBytes = $this->attachment_size_bytes ? (int) $this->attachment_size_bytes : null;

        return [
            'id' => $this->uuid,
            'uuid' => $this->uuid,
            'homeworkId' => $this->whenLoaded('homework', fn () => $this->homework?->uuid),
            'studentId' => $student ? (string) $student->id : null,
            'studentNameAr' => $student?->name ?? 'طالب مجهول',
            'studentEmail' => $student?->email ?? null,
            'studentAvatar' => $student?->avatar,
            'studentCode' => $student?->student_code ?? null,
            'locationAr' => $this->location_ar ?? 'أونلاين',
            'submittedAt' => $this->formatDate($this->submitted_at),
            'type' => $this->submission_type ?? ($this->attachment_path ? 'file' : 'text'),
            'attachmentName' => $this->attachment_name,
            'attachmentDisk' => $this->attachment_path ? ($this->attachment_disk ?: 'local') : null,
            'attachmentMimeType' => $this->attachment_mime_type,
            'attachmentSizeBytes' => $sizeBytes,
            'attachmentSizeLabel' => HomeworkAttachmentStorage::formatBytes($sizeBytes),
            'attachmentUrl' => $attachmentUrl,
            'attachmentDownloadUrl' => $attachmentDownloadUrl,
            'solutionText' => $this->solution_text,
            'sampleSolutionText' => $this->solution_text,
            'status' => $status,
            'statusLabelAr' => $statusLabelAr,
            'score' => $this->score !== null ? (float) $this->score : null,
            'maxScore' => (int) $this->max_score,
            'feedbackAr' => $this->feedback_ar ?? '',
            'gradedAt' => $this->formatDate($this->graded_at),
            'grader' => $this->whenLoaded('grader', fn () => [
                'id' => $this->grader?->id,
                'name' => $this->grader?->name,
            ]),
        ];
    }
}
