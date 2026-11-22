<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\HomeworkType;
use App\Http\Resources\BaseJsonResource;
use App\Services\V1\Storage\HomeworkAttachmentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class TeacherHomeworkResource extends BaseJsonResource
{
    /**
     * Transform the homework resource for TeacherHomeworkView.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof HomeworkType
            ? $this->type->value
            : (string) ($this->type ?? ($this->attachment_path ? 'file' : 'text'));

        $attachmentUrl = null;
        $attachmentDownloadUrl = null;
        if (! empty($this->attachment_path)) {
            if (Route::has('api.v1.teacher.homeworks.attachment.stream')) {
                $expiry = now()->addHours(2);
                $attachmentUrl = URL::temporarySignedRoute(
                    'api.v1.teacher.homeworks.attachment.stream',
                    $expiry,
                    ['uuid' => $this->uuid]
                );
                $attachmentDownloadUrl = URL::temporarySignedRoute(
                    'api.v1.teacher.homeworks.attachment.stream',
                    $expiry,
                    ['uuid' => $this->uuid, 'download' => 1]
                );
            } else {
                $attachmentUrl = url("/api/v1/teacher/homeworks/{$this->uuid}/attachment");
                $attachmentDownloadUrl = url("/api/v1/teacher/homeworks/{$this->uuid}/attachment?download=1");
            }
        }

        $sizeBytes = $this->attachment_size_bytes ? (int) $this->attachment_size_bytes : null;

        return [
            'id' => $this->uuid,
            'uuid' => $this->uuid,
            'titleAr' => $this->title_ar,
            'courseId' => $this->whenLoaded('course', fn () => $this->course?->uuid),
            'courseTitleAr' => $this->course_title_ar ?: ($this->whenLoaded('course', fn () => $this->course?->title_ar) ?? 'المقرر الدراسي'),
            'lessonId' => $this->whenLoaded('lesson', fn () => $this->lesson?->uuid),
            'lessonAr' => $this->lesson_title_ar ?: ($this->whenLoaded('lesson', fn () => $this->lesson?->title_ar) ?? ''),
            'targetLocationId' => $this->target_location_id,
            'targetLocationAr' => $this->target_location_ar,
            'type' => $type,
            'fileType' => $this->file_type,
            'attachmentName' => $this->attachment_name,
            'attachmentDisk' => $this->attachment_path ? ($this->attachment_disk ?: 'local') : null,
            'attachmentMimeType' => $this->attachment_mime_type,
            'attachmentSizeBytes' => $sizeBytes,
            'attachmentSizeLabel' => HomeworkAttachmentStorage::formatBytes($sizeBytes),
            'attachmentUrl' => $attachmentUrl,
            'attachmentDownloadUrl' => $attachmentDownloadUrl,
            'textContent' => $this->text_content,
            'dueDate' => $this->due_date?->toDateString(),
            'dueTime' => $this->due_time,
            'maxGrade' => (int) $this->max_grade,
            'totalAssigned' => (int) $this->total_assigned,
            'submittedCount' => (int) $this->submitted_count,
            'gradedCount' => (int) $this->graded_count,
            'pendingGrading' => (int) $this->pending_grading,
            'avgGrade' => (float) $this->avg_grade,
            'createdAt' => $this->formatDate($this->created_at),
            'updatedAt' => $this->formatDate($this->updated_at),
        ];
    }
}
