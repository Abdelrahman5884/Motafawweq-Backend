<?php

namespace App\Services\V1\Teacher;

use App\Enums\LessonAttachmentType;
use App\Enums\LessonMediaMode;
use App\Enums\LessonStatus;
use App\Enums\TeacherPlanTier;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\StoredFile;
use App\Models\User;
use App\Repositories\Contracts\CourseRepositoryInterface;
use App\Repositories\Contracts\LessonRepositoryInterface;
use App\Services\BaseService;
use finfo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherStudioService extends BaseService
{
    public function __construct(
        protected LessonRepositoryInterface $lessonRepository,
        protected CourseRepositoryInterface $courseRepository
    ) {}

    public function listLessons(User $teacher, array $filters = []): Collection
    {
        return $this->lessonRepository->getForTeacher($teacher->id, $filters);
    }

    public function getLesson(User $teacher, string $uuid): Lesson
    {
        return $this->lessonRepository->findByUuidForTeacher($uuid, $teacher);
    }

    /**
     * Create a new studio lesson with metadata, embed URL, and AI feature flags.
     */
    public function createLesson(User $teacher, array $data): Lesson
    {
        $course = null;
        $courseUuid = $data['course_uuid'] ?? $data['courseUuid'] ?? $data['courseId'] ?? $data['course_id'] ?? null;
        if (is_string($courseUuid) && Str::isUuid($courseUuid)) {
            // Verify ownership BEFORE opening transaction
            $course = $this->courseRepository->findByUuidForTeacher($courseUuid, $teacher);
        }

        $canUseAi = $this->teacherCanUseAi($teacher);

        return $this->transaction(function () use ($teacher, $data, $course, $canUseAi) {
            $titleAr = trim((string) ($data['title_ar'] ?? $data['titleAr'] ?? $data['title'] ?? ''));
            $titleEn = trim((string) ($data['title_en'] ?? $data['titleEn'] ?? $data['title'] ?? $titleAr));
            $descAr = trim((string) ($data['description_ar'] ?? $data['descriptionAr'] ?? $data['description'] ?? ''));
            $descEn = trim((string) ($data['description_en'] ?? $data['descriptionEn'] ?? $descAr));

            $subjectAr = trim((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? $data['subject'] ?? $course?->subject_ar ?? 'الأحياء'));
            $gradeAr = $this->normalizeGradeAr((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? $data['grade'] ?? $course?->grade_ar ?? 'الصف الثالث الثانوي'));
            $targetGroup = trim((string) ($data['target_group_code'] ?? $data['classGroup'] ?? $data['targetGroup'] ?? 'cls-dokki-301'));

            $rawEmbedUrl = trim((string) ($data['embed_url'] ?? $data['embedUrl'] ?? $data['videoUrl'] ?? ''));
            [$safeEmbedUrl, $isYoutube] = $this->sanitizeEmbedUrl($rawEmbedUrl);

            $modeValue = (string) ($data['media_mode'] ?? $data['mode'] ?? ($safeEmbedUrl ? 'embed' : 'video'));
            $mediaMode = LessonMediaMode::tryFrom($modeValue) ?? LessonMediaMode::VIDEO;

            $durationSecs = max(0, (int) ($data['duration_seconds'] ?? $data['durationSeconds'] ?? 2700));
            $durationLabel = trim((string) ($data['duration_label'] ?? $data['durationLabel'] ?? $this->formatSecondsLabel($durationSecs)));

            $aiFeatures = $this->normalizeAiFeatures(
                $data['ai_features_requested'] ?? $data['aiFeatures'] ?? null,
                $canUseAi
            );
            $status = LessonStatus::tryFrom((string) ($data['status'] ?? 'published')) ?? LessonStatus::PUBLISHED;

            /** @var Lesson $lesson */
            $lesson = $this->lessonRepository->create([
                'teacher_id'            => $teacher->id,
                'course_id'             => $course?->id,
                'title_ar'              => $titleAr,
                'title_en'              => $titleEn,
                'description_ar'        => $descAr,
                'description_en'        => $descEn,
                'subject_ar'            => $subjectAr,
                'grade_ar'              => $gradeAr,
                'target_group_code'     => $targetGroup !== '' ? $targetGroup : null,
                'media_mode'            => $mediaMode,
                'embed_url'             => $safeEmbedUrl,
                'is_youtube'            => $isYoutube,
                'duration_seconds'      => $durationSecs,
                'duration_label'        => $durationLabel,
                'ai_features_requested' => $aiFeatures,
                'status'                => $status,
                'published_at'          => $status === LessonStatus::PUBLISHED ? now() : null,
            ]);

            if ($course) {
                $this->syncCourseLessonsCount($course->id);
            }

            $this->logSecurityEvent(
                'teacher.lesson.created',
                [
                    'lesson_uuid' => $lesson->uuid,
                    'course_uuid' => $course?->uuid,
                    'media_mode'  => $mediaMode->value,
                    'ai_features' => $aiFeatures,
                ],
                'info',
                $lesson
            );

            return $lesson->fresh(['course', 'attachments']);
        });
    }

    /**
     * Update an existing lesson's metadata, embed URL, or AI feature flags.
     */
    public function updateLesson(User $teacher, string $uuid, array $data): Lesson
    {
        $lesson = $this->lessonRepository->findByUuidForTeacher($uuid, $teacher);

        $course = null;
        $hasCourseUpdate = array_key_exists('course_uuid', $data)
            || array_key_exists('courseUuid', $data)
            || array_key_exists('courseId', $data)
            || array_key_exists('course_id', $data);
        if ($hasCourseUpdate) {
            $courseUuid = $data['course_uuid'] ?? $data['courseUuid'] ?? $data['courseId'] ?? $data['course_id'];
            if (is_string($courseUuid) && Str::isUuid($courseUuid)) {
                $course = $this->courseRepository->findByUuidForTeacher($courseUuid, $teacher);
            }
        }

        $canUseAi = $this->teacherCanUseAi($teacher);

        return $this->transaction(function () use ($lesson, $data, $hasCourseUpdate, $course, $canUseAi) {
            $oldCourseId = $lesson->course_id;
            $updates = [];

            if ($hasCourseUpdate) {
                $updates['course_id'] = $course?->id;
            }

            if (array_key_exists('title_ar', $data) || array_key_exists('titleAr', $data) || array_key_exists('title', $data)) {
                $titleAr = trim((string) ($data['title_ar'] ?? $data['titleAr'] ?? $data['title']));
                if ($titleAr !== '') {
                    $updates['title_ar'] = $titleAr;
                }
            }

            if (array_key_exists('title_en', $data) || array_key_exists('titleEn', $data)) {
                $updates['title_en'] = trim((string) ($data['title_en'] ?? $data['titleEn']));
            }

            if (array_key_exists('description_ar', $data) || array_key_exists('descriptionAr', $data) || array_key_exists('description', $data)) {
                $updates['description_ar'] = trim((string) ($data['description_ar'] ?? $data['descriptionAr'] ?? $data['description']));
            }

            if (array_key_exists('subject_ar', $data) || array_key_exists('subjectAr', $data) || array_key_exists('subject', $data)) {
                $updates['subject_ar'] = trim((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? $data['subject']));
            }

            if (array_key_exists('grade_ar', $data) || array_key_exists('gradeAr', $data) || array_key_exists('grade', $data)) {
                $updates['grade_ar'] = $this->normalizeGradeAr((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? $data['grade']));
            }

            if (array_key_exists('target_group_code', $data) || array_key_exists('classGroup', $data) || array_key_exists('targetGroup', $data)) {
                $updates['target_group_code'] = trim((string) ($data['target_group_code'] ?? $data['classGroup'] ?? $data['targetGroup']));
            }

            if (array_key_exists('embed_url', $data) || array_key_exists('embedUrl', $data) || array_key_exists('videoUrl', $data)) {
                $rawUrl = trim((string) ($data['embed_url'] ?? $data['embedUrl'] ?? $data['videoUrl']));
                [$safeUrl, $isYoutube] = $this->sanitizeEmbedUrl($rawUrl);
                $updates['embed_url'] = $safeUrl;
                $updates['is_youtube'] = $isYoutube;
            }

            if (array_key_exists('media_mode', $data) || array_key_exists('mode', $data)) {
                $mode = LessonMediaMode::tryFrom((string) ($data['media_mode'] ?? $data['mode']));
                if ($mode) {
                    $updates['media_mode'] = $mode;
                }
            }

            if (array_key_exists('ai_features_requested', $data) || array_key_exists('aiFeatures', $data)) {
                $updates['ai_features_requested'] = $this->normalizeAiFeatures(
                    $data['ai_features_requested'] ?? $data['aiFeatures'],
                    $canUseAi
                );
            }

            if (array_key_exists('status', $data)) {
                $status = LessonStatus::tryFrom((string) $data['status']);
                if ($status) {
                    $updates['status'] = $status;
                    if ($status === LessonStatus::PUBLISHED && !$lesson->published_at) {
                        $updates['published_at'] = now();
                    }
                }
            }

            if (!empty($updates)) {
                $lesson->update($updates);
            }

            if ($hasCourseUpdate) {
                $this->syncCourseLessonsCount($oldCourseId);
                $this->syncCourseLessonsCount($course?->id);
            }

            $this->logSecurityEvent(
                'teacher.lesson.updated',
                [
                    'lesson_uuid'    => $lesson->uuid,
                    'updated_fields' => array_keys($updates),
                ],
                'info',
                $lesson
            );

            return $lesson->fresh(['course', 'attachments']);
        });
    }

    /**
     * Soft-delete a lesson owned by the teacher.
     */
    public function deleteLesson(User $teacher, string $uuid): void
    {
        $lesson = $this->lessonRepository->findByUuidForTeacher($uuid, $teacher);

        $this->transaction(function () use ($lesson) {
            $courseId = $lesson->course_id;
            $lessonUuid = $lesson->uuid;

            $lesson->delete();

            if ($courseId) {
                $this->syncCourseLessonsCount($courseId);
            }

            $this->logSecurityEvent(
                'teacher.lesson.deleted',
                ['lesson_uuid' => $lessonUuid],
                'warning'
            );
        });
    }

    /**
     * Get target storage disk (supabase with fallback to local).
     */
    protected function getStorageDisk(): string
    {
        if (Storage::disk('local') instanceof \Illuminate\Support\Testing\Fakes\StorageFake) {
            return 'local';
        }
        if (Storage::disk('supabase') instanceof \Illuminate\Support\Testing\Fakes\StorageFake) {
            return 'supabase';
        }
        return config('filesystems.disks.supabase.key') ? 'supabase' : 'local';
    }

    /**
     * Securely inspect magic bytes and store an audio/video recording on the private disk.
     */
    public function uploadMedia(
        User $teacher,
        string $lessonUuid,
        UploadedFile $file,
        ?string $mode = null,
        ?int $durationSeconds = null
    ): Lesson {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        $realPath = $file->getRealPath();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $realPath ? $finfo->file($realPath) : $file->getMimeType();

        $allowedMediaMimes = [
            'audio/mpeg'      => 'mp3',
            'audio/mp3'       => 'mp3',
            'audio/wav'       => 'wav',
            'audio/x-wav'     => 'wav',
            'audio/webm'      => 'webm',
            'audio/ogg'       => 'ogg',
            'audio/mp4'       => 'm4a',
            'video/mp4'       => 'mp4',
            'video/webm'      => 'webm',
            'video/ogg'       => 'ogv',
            'video/quicktime' => 'mov',
        ];

        if (!isset($allowedMediaMimes[$detectedMime])) {
            $this->logSecurityEvent(
                'teacher.upload.malicious_signature_blocked',
                [
                    'lesson_uuid'   => $lesson->uuid,
                    'detected_mime' => $detectedMime,
                    'original_name' => $file->getClientOriginalName(),
                    'context'       => 'lesson_media',
                ],
                'warning',
                $lesson
            );

            throw ValidationException::withMessages([
                'media' => [__('messages.invalid_media_signature')],
            ]);
        }

        return $this->transaction(function () use ($teacher, $lesson, $file, $detectedMime, $allowedMediaMimes, $mode, $durationSeconds) {
            $extension = $allowedMediaMimes[$detectedMime];
            $disk = $this->getStorageDisk();
            $storagePath = "lessons/audio/{$teacher->id}_{$lesson->uuid}_" . Str::uuid() . ".{$extension}";

            Storage::disk($disk)->put($storagePath, file_get_contents($file->getRealPath()));

            if ($lesson->media_path && Storage::disk($lesson->media_disk ?? $disk)->exists($lesson->media_path)) {
                Storage::disk($lesson->media_disk ?? $disk)->delete($lesson->media_path);
            }

            $resolvedMode = $mode
                ? (LessonMediaMode::tryFrom($mode) ?? (str_starts_with($detectedMime, 'audio/') ? LessonMediaMode::AUDIO : LessonMediaMode::VIDEO))
                : (str_starts_with($detectedMime, 'audio/') ? LessonMediaMode::AUDIO : LessonMediaMode::VIDEO);

            $secs = $durationSeconds !== null && $durationSeconds > 0 ? $durationSeconds : ($lesson->duration_seconds ?: 2700);

            $lesson->update([
                'media_mode'          => $resolvedMode,
                'media_disk'          => $disk,
                'media_path'          => $storagePath,
                'media_original_name' => $this->sanitizeOriginalFileName($file->getClientOriginalName()),
                'media_mime_type'     => $detectedMime,
                'media_size_bytes'    => $file->getSize(),
                'duration_seconds'    => $secs,
                'duration_label'      => $this->formatSecondsLabel($secs),
            ]);

            StoredFile::updateOrCreate(
                ['storage_path' => $storagePath],
                [
                    'uuid'              => (string) Str::uuid(),
                    'user_id'           => $teacher->id,
                    'entity_type'       => 'lesson_media',
                    'entity_id'         => $lesson->uuid,
                    'original_filename' => $this->sanitizeOriginalFileName($file->getClientOriginalName()),
                    'storage_disk'      => $disk,
                    'mime_type'         => $detectedMime,
                    'file_size_bytes'   => (int) $file->getSize(),
                    'storage_provider'  => $disk === 'supabase' ? 'supabase' : 'local',
                    'upload_status'     => 'completed',
                ]
            );

            $this->logSecurityEvent(
                'teacher.lesson.media_uploaded',
                [
                    'lesson_uuid' => $lesson->uuid,
                    'mime_type'   => $detectedMime,
                    'size_bytes'  => $file->getSize(),
                ],
                'info',
                $lesson
            );

            return $lesson->fresh(['course', 'attachments']);
        });
    }

    /**
     * Securely inspect magic bytes and store a PDF booklet or Whiteboard Image attachment on the private disk.
     */
    public function uploadAttachment(
        User $teacher,
        string $lessonUuid,
        UploadedFile $file,
        LessonAttachmentType $type,
        array $meta = []
    ): LessonAttachment {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        $realPath = $file->getRealPath();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $realPath ? $finfo->file($realPath) : $file->getMimeType();
        $rawContent = $realPath ? file_get_contents($realPath) : '';

        if ($type === LessonAttachmentType::PDF) {
            $hasPdfHeader = str_starts_with($rawContent, '%PDF-');
            if ($detectedMime !== 'application/pdf' || !$hasPdfHeader) {
                $this->logSecurityEvent(
                    'teacher.upload.malicious_signature_blocked',
                    [
                        'lesson_uuid'   => $lesson->uuid,
                        'detected_mime' => $detectedMime,
                        'original_name' => $file->getClientOriginalName(),
                        'context'       => 'lesson_pdf',
                    ],
                    'warning',
                    $lesson
                );

                throw ValidationException::withMessages([
                    'file' => [__('messages.invalid_pdf_signature')],
                ]);
            }
            $extension = 'pdf';
        } else {
            $allowedImages = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($allowedImages[$detectedMime])) {
                $this->logSecurityEvent(
                    'teacher.upload.malicious_signature_blocked',
                    [
                        'lesson_uuid'   => $lesson->uuid,
                        'detected_mime' => $detectedMime,
                        'original_name' => $file->getClientOriginalName(),
                        'context'       => 'lesson_whiteboard_image',
                    ],
                    'warning',
                    $lesson
                );

                throw ValidationException::withMessages([
                    'file' => [__('messages.invalid_image_signature')],
                ]);
            }
            $extension = $allowedImages[$detectedMime];
        }

        return $this->transaction(function () use ($teacher, $lesson, $file, $rawContent, $type, $detectedMime, $extension, $meta) {
            $disk = $this->getStorageDisk();
            $storagePath = "lessons/attachments/{$teacher->id}_{$lesson->uuid}_" . Str::uuid() . ".{$extension}";

            Storage::disk($disk)->put($storagePath, $rawContent);

            $nextOrder = (int) LessonAttachment::where('lesson_id', $lesson->id)
                ->where('type', $type)
                ->max('display_order') + 1;

            $cleanFileName = $this->sanitizeOriginalFileName($file->getClientOriginalName());
            $defaultTitle = pathinfo($cleanFileName, PATHINFO_FILENAME) ?: 'مرفق الحصة';
            if ($type === LessonAttachmentType::WHITEBOARD_IMAGE && empty($meta['title'])) {
                $defaultTitle = "سبورة رقم {$nextOrder}: {$defaultTitle}";
            }

            $sizeBytes = (int) $file->getSize();

            $attachment = LessonAttachment::create([
                'lesson_id'          => $lesson->id,
                'teacher_id'         => $teacher->id,
                'type'               => $type,
                'title'              => trim((string) ($meta['title'] ?? $defaultTitle)),
                'caption'            => isset($meta['caption']) ? trim((string) $meta['caption']) : ($type === LessonAttachmentType::WHITEBOARD_IMAGE ? 'رسم توضيحي للشرح وملاحظات السبورة' : null),
                'original_file_name' => $cleanFileName,
                'storage_disk'       => $disk,
                'storage_path'       => $storagePath,
                'mime_type'          => $detectedMime,
                'file_size_bytes'    => $sizeBytes,
                'file_size_label'    => $this->formatBytes($sizeBytes),
                'pages_count'        => $type === LessonAttachmentType::PDF
                    ? max(1, (int) ($meta['pages_count'] ?? $meta['pagesCount'] ?? 12))
                    : null,
                'display_order'      => $nextOrder,
            ]);

            StoredFile::create([
                'uuid'              => (string) Str::uuid(),
                'user_id'           => $teacher->id,
                'entity_type'       => 'lesson_attachment',
                'entity_id'         => $lesson->uuid,
                'original_filename' => $cleanFileName,
                'storage_disk'      => $disk,
                'storage_path'      => $storagePath,
                'mime_type'         => $detectedMime,
                'file_size_bytes'   => $sizeBytes,
                'storage_provider'  => $disk === 'supabase' ? 'supabase' : 'local',
                'upload_status'     => 'completed',
                'metadata'          => [
                    'attachment_uuid' => $attachment->uuid,
                    'type'            => $type->value,
                    'title'           => $attachment->title,
                ],
            ]);

            $this->logSecurityEvent(
                'teacher.lesson.attachment_uploaded',
                [
                    'lesson_uuid'     => $lesson->uuid,
                    'attachment_uuid' => $attachment->uuid,
                    'type'            => $type->value,
                    'mime_type'       => $detectedMime,
                    'size_bytes'      => $sizeBytes,
                ],
                'info',
                $attachment
            );

            return $attachment;
        });
    }

    /**
     * Reorder whiteboard images or PDFs sequentially (1, 2, 3...).
     */
    public function reorderAttachments(
        User $teacher,
        string $lessonUuid,
        array $orderedUuids,
        ?string $type = null
    ): Collection {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        return $this->transaction(function () use ($lesson, $orderedUuids, $type) {
            $attachmentType = $type
                ? (LessonAttachmentType::tryFrom($type) ?? LessonAttachmentType::WHITEBOARD_IMAGE)
                : LessonAttachmentType::WHITEBOARD_IMAGE;

            $attachments = LessonAttachment::where('lesson_id', $lesson->id)
                ->where('type', $attachmentType)
                ->orderBy('display_order')
                ->get()
                ->keyBy('uuid');

            $order = 1;
            foreach ($orderedUuids as $uuid) {
                if ($attachments->has($uuid)) {
                    $attachments->get($uuid)->update(['display_order' => $order++]);
                    $attachments->forget($uuid);
                }
            }

            foreach ($attachments as $remaining) {
                $remaining->update(['display_order' => $order++]);
            }

            $this->logSecurityEvent(
                'teacher.lesson.attachments_reordered',
                [
                    'lesson_uuid' => $lesson->uuid,
                    'type'        => $attachmentType->value,
                ],
                'info',
                $lesson
            );

            return LessonAttachment::where('lesson_id', $lesson->id)
                ->where('type', $attachmentType)
                ->orderBy('display_order')
                ->get();
        });
    }

    /**
     * Delete a lesson attachment from disk and database, and re-sequence remaining display_order.
     */
    public function deleteAttachment(User $teacher, string $lessonUuid, string $attachmentUuid): void
    {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);
        $attachment = $this->lessonRepository->findAttachmentByUuidForTeacher($attachmentUuid, $lesson, $teacher);

        $this->transaction(function () use ($lesson, $attachment) {
            $type = $attachment->type;
            $disk = $attachment->storage_disk ?? 'local';
            $path = $attachment->storage_path;
            $attUuid = $attachment->uuid;

            $attachment->delete();

            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }

            StoredFile::where('storage_path', $path)->delete();

            // Re-index remaining attachments of the same type sequentially (1, 2, 3...)
            $remaining = LessonAttachment::where('lesson_id', $lesson->id)
                ->where('type', $type)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

            foreach ($remaining as $index => $item) {
                $item->update(['display_order' => $index + 1]);
            }

            $this->logSecurityEvent(
                'teacher.lesson.attachment_deleted',
                [
                    'lesson_uuid'     => $lesson->uuid,
                    'attachment_uuid' => $attUuid,
                    'type'            => $type->value,
                ],
                'warning'
            );
        });
    }

    /**
     * Sanitize external video embed URLs and convert YouTube links to safe embed URLs.
     *
     * @return array{0: string|null, 1: bool}
     */
    protected function sanitizeEmbedUrl(string $rawUrl): array
    {
        if ($rawUrl === '') {
            return [null, false];
        }

        if (!preg_match('#^https://#i', $rawUrl)) {
            throw ValidationException::withMessages([
                'embed_url' => [__('messages.invalid_embed_url')],
            ]);
        }

        $isYoutube = false;
        $embedUrl = $rawUrl;

        if (str_contains($rawUrl, 'youtube.com/watch?v=')) {
            $parts = explode('v=', $rawUrl);
            $videoId = explode('&', $parts[1] ?? '')[0] ?? '';
            if (preg_match('/^[a-zA-Z0-9_-]{6,20}$/', $videoId)) {
                $embedUrl = "https://www.youtube.com/embed/{$videoId}";
                $isYoutube = true;
            }
        } elseif (str_contains($rawUrl, 'youtu.be/')) {
            $parts = explode('youtu.be/', $rawUrl);
            $videoId = explode('?', $parts[1] ?? '')[0] ?? '';
            if (preg_match('/^[a-zA-Z0-9_-]{6,20}$/', $videoId)) {
                $embedUrl = "https://www.youtube.com/embed/{$videoId}";
                $isYoutube = true;
            }
        } elseif (str_contains($rawUrl, 'youtube.com/embed/')) {
            $isYoutube = true;
        }

        return [$embedUrl, $isYoutube];
    }

    protected function teacherCanUseAi(User $teacher): bool
    {
        $tier = $teacher->teacherProfile?->plan_tier;
        if ($tier instanceof TeacherPlanTier) {
            return $tier->allowsAiFeatures();
        }

        return $tier === TeacherPlanTier::PRO->value;
    }

    protected function syncCourseLessonsCount(?int $courseId): void
    {
        if (!$courseId) {
            return;
        }

        $course = Course::find($courseId);
        if (!$course) {
            return;
        }

        $course->update([
            'lessons_count' => $course->lessons()->count(),
        ]);
    }

    protected function normalizeAiFeatures(mixed $raw, bool $canUseAi = true): array
    {
        if (!$canUseAi) {
            return [
                'speechToText'    => false,
                'conceptGraph'    => false,
                'chapterIndexing' => false,
            ];
        }

        if (!is_array($raw)) {
            return [
                'speechToText'    => true,
                'conceptGraph'    => true,
                'chapterIndexing' => true,
            ];
        }

        return [
            'speechToText'    => (bool) ($raw['speechToText'] ?? $raw['speech_to_text'] ?? true),
            'conceptGraph'    => (bool) ($raw['conceptGraph'] ?? $raw['concept_graph'] ?? true),
            'chapterIndexing' => (bool) ($raw['chapterIndexing'] ?? $raw['chapter_indexing'] ?? true),
        ];
    }

    protected function normalizeGradeAr(string $grade): string
    {
        return match (trim($grade)) {
            '3rd Secondary' => 'الصف الثالث الثانوي',
            '2nd Secondary' => 'الصف الثاني الثانوي',
            '1st Secondary' => 'الصف الأول الثانوي',
            '3rd Prep'      => 'الصف الثالث الإعدادي',
            default         => trim($grade) ?: 'الصف الثالث الثانوي',
        };
    }

    protected function sanitizeOriginalFileName(string $name): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s._\-()]/u', '', basename($name));

        return mb_substr(trim((string) $clean) ?: 'attachment', 0, 200);
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    protected function formatSecondsLabel(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return sprintf('%02d:%02d', $m, $s);
    }
}

