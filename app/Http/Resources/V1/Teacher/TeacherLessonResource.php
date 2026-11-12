<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\LessonAttachmentType;
use App\Enums\LessonMediaMode;
use App\Enums\LessonStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class TeacherLessonResource extends BaseJsonResource
{
    /**
     * Transform the Lesson model into the exact frontend payload for RecordingStudio & LessonWorkspace.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mode = $this->media_mode instanceof LessonMediaMode
            ? $this->media_mode->value
            : (string) ($this->media_mode ?? 'video');

        $status = $this->status instanceof LessonStatus
            ? $this->status->value
            : (string) ($this->status ?? 'published');

        $attachments = $this->relationLoaded('attachments') ? $this->attachments : collect();
        $attachments->each(fn ($a) => $a->setRelation('lesson', $this->resource));
        $pdfs = $attachments->filter(fn ($a) => ($a->type instanceof LessonAttachmentType ? $a->type : LessonAttachmentType::tryFrom((string) $a->type)) === LessonAttachmentType::PDF)->values();
        $images = $attachments->filter(fn ($a) => ($a->type instanceof LessonAttachmentType ? $a->type : LessonAttachmentType::tryFrom((string) $a->type)) === LessonAttachmentType::WHITEBOARD_IMAGE)->values();

        $chapters = $this->relationLoaded('chapters') ? $this->chapters : collect();
        $transcript = $this->relationLoaded('transcriptSegments') ? $this->transcriptSegments : collect();
        $conceptGraph = $this->relationLoaded('conceptGraph') ? $this->conceptGraph : null;

        $mediaStreamUrl = null;
        if ($this->media_path && Route::has('api.v1.teacher.lessons.media.stream')) {
            $mediaStreamUrl = URL::temporarySignedRoute(
                'api.v1.teacher.lessons.media.stream',
                now()->addMinutes(30),
                ['uuid' => $this->uuid]
            );
        }

        $mediaType = in_array($mode, ['mic', 'audio'], true) ? 'audio' : 'video';

        return [
            'id'                => $this->uuid,
            'courseId'          => $this->whenLoaded('course', fn () => $this->course?->uuid),
            'courseTitleAr'     => $this->whenLoaded('course', fn () => $this->course?->title_ar),
            'unitAr'            => $this->whenLoaded('course', fn () => $this->course?->title_ar) ?? $this->subject_ar,
            'title'             => $this->title_ar,
            'titleAr'           => $this->title_ar,
            'titleEn'           => $this->title_en ?? $this->title_ar,
            'description'       => $this->description_ar,
            'descriptionAr'     => $this->description_ar,
            'descriptionEn'     => $this->description_en ?? $this->description_ar,
            'subject'           => $this->subject_ar,
            'subjectAr'         => $this->subject_ar,
            'grade'             => $this->grade_ar,
            'gradeAr'           => $this->grade_ar,
            'classGroup'        => $this->target_group_code,
            'mode'              => $mode,
            'mediaType'         => $mediaType,
            'mediaOriginalName' => $this->media_original_name,
            'mediaMimeType'     => $this->media_mime_type,
            'mediaSizeBytes'    => $this->media_size_bytes,
            'mediaStreamUrl'    => $mediaStreamUrl,
            'embedUrl'          => $this->embed_url,
            'videoUrl'          => $this->embed_url ?? $mediaStreamUrl,
            'isYoutube'         => (bool) $this->is_youtube,
            'durationSeconds'   => (int) $this->duration_seconds,
            'durationLabel'     => $this->duration_label,
            'durationFormatted' => $this->duration_label,
            'aiFeatures'        => $this->ai_features_requested ?? [
                'speechToText'    => true,
                'conceptGraph'    => true,
                'chapterIndexing' => true,
            ],
            'status'            => $status,
            'lessonPdfs'        => LessonAttachmentResource::collection($pdfs)->resolve($request),
            'lessonImages'      => LessonAttachmentResource::collection($images)->resolve($request),
            'chapters'          => LessonChapterResource::collection($chapters)->resolve($request),
            'transcript'        => LessonTranscriptResource::collection($transcript)->resolve($request),
            'notebookMindMap'   => $conceptGraph?->tree_data,
            'publishedAt'       => $this->formatDate($this->published_at),
            'createdAt'         => $this->formatDate($this->created_at),
            'updatedAt'         => $this->formatDate($this->updated_at),
        ];
    }
}

