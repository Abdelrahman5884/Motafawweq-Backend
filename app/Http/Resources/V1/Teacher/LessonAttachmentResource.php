<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\LessonAttachmentType;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class LessonAttachmentResource extends BaseJsonResource
{
    /**
     * Transform the lesson attachment into the structure expected by LessonAttachmentsManager.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof LessonAttachmentType
            ? $this->type->value
            : (string) $this->type;

        $lessonUuid = $this->relationLoaded('lesson') && $this->lesson
            ? $this->lesson->uuid
            : $request->route('uuid');

        $signedUrl = '#';
        if ($lessonUuid && Route::has('api.v1.teacher.lessons.attachments.stream')) {
            $signedUrl = URL::temporarySignedRoute(
                'api.v1.teacher.lessons.attachments.stream',
                now()->addMinutes(30),
                [
                    'uuid'           => $lessonUuid,
                    'attachmentUuid' => $this->uuid,
                ]
            );
        }

        return array_filter([
            'id'         => $this->uuid,
            'type'       => $type,
            'order'      => (int) $this->display_order,
            'title'      => $this->title,
            'caption'    => $this->caption,
            'fileName'   => $this->original_file_name,
            'fileSize'   => $this->file_size_label,
            'sizeBytes'  => (int) $this->file_size_bytes,
            'mimeType'   => $this->mime_type,
            'pagesCount' => $this->pages_count !== null ? (int) $this->pages_count : null,
            'url'        => $signedUrl,
            'uploadedAt' => $this->created_at?->format('h:i A'),
            'createdAt'  => $this->formatDate($this->created_at),
        ], fn ($v) => $v !== null);
    }
}

