<?php

namespace App\Services\V1\Storage;

use App\Enums\LessonAttachmentType;
use App\Enums\LessonMediaMode;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\BaseService;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupabaseStorageService extends BaseService
{
    /**
     * Supabase Free Plan enforces a strict 50 MB hard limit per file.
     */
    public const MAX_FILE_SIZE_BYTES = 52428800; // 50 * 1024 * 1024 (50 MB)

    /**
     * Storage disk name configured in filesystems.php
     */
    protected string $diskName = 'supabase';

    /**
     * Allowed entity types and their corresponding directory paths & constraints.
     */
    protected array $entityConfigs = [
        'user_avatar' => [
            'folder'        => 'users/profiles',
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size'      => 5242880, // 5 MB
        ],
        'center_logo' => [
            'folder'        => 'centers/logos',
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'],
            'max_size'      => 5242880, // 5 MB
        ],
        'center_document' => [
            'folder'        => 'centers/documents',
            'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
            'max_size'      => 20971520, // 20 MB
        ],
        'course_thumbnail' => [
            'folder'        => 'courses/thumbnails',
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size'      => 10485760, // 10 MB
        ],
        'course_resource' => [
            'folder'        => 'courses/resources',
            'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
            'max_size'      => self::MAX_FILE_SIZE_BYTES,
        ],
        'lesson_media' => [
            'folder'        => 'lessons/audio',
            'allowed_mimes' => [
                'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav',
                'audio/webm', 'audio/ogg', 'audio/aac', 'audio/m4a', 'audio/x-m4a',
                'video/mp4', 'video/webm'
            ],
            'max_size'      => self::MAX_FILE_SIZE_BYTES,
        ],
        'lesson_attachment' => [
            'folder'        => 'lessons/attachments',
            'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
            'max_size'      => self::MAX_FILE_SIZE_BYTES,
        ],
        'lesson_transcript' => [
            'folder'        => 'lessons/transcripts',
            'allowed_mimes' => ['application/json', 'text/plain', 'application/pdf'],
            'max_size'      => 10485760, // 10 MB
        ],
        'lesson_mindmap' => [
            'folder'        => 'lessons/mindmaps',
            'allowed_mimes' => ['application/json', 'image/png', 'image/jpeg', 'image/svg+xml'],
            'max_size'      => 10485760, // 10 MB
        ],
        'lesson_quiz' => [
            'folder'        => 'lessons/quizzes',
            'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
            'max_size'      => 20971520, // 20 MB
        ],
        'student_submission' => [
            'folder'        => 'students/submissions',
            'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
            'max_size'      => self::MAX_FILE_SIZE_BYTES,
        ],
    ];

    /**
     * Get the active filesystem disk.
     */
    public function disk()
    {
        return Storage::disk($this->diskName);
    }

    /**
     * Get the disk name.
     */
    public function getDiskName(): string
    {
        return $this->diskName;
    }

    /**
     * Set the disk name (useful for testing with 'local' or 'public').
     */
    public function setDiskName(string $name): self
    {
        $this->diskName = $name;
        return $this;
    }

    /**
     * Generate a presigned S3 upload URL for secure direct uploads from React.
     */
    public function generatePresignedUploadUrl(
        User $user,
        string $entityType,
        ?string $entityId,
        string $filename,
        string $mimeType,
        int $fileSizeBytes,
        array $extraMetadata = []
    ): array {
        $this->validateUploadRequest($user, $entityType, $entityId, $mimeType, $fileSizeBytes);

        $config = $this->entityConfigs[$entityType];
        $extension = $this->resolveExtension($filename, $mimeType);
        $safeOriginalName = $this->sanitizeFilename($filename);
        $uniqueKey = Str::uuid()->toString();

        // Organize paths strictly according to platform architecture
        $storagePath = sprintf('%s/%s_%s.%s', $config['folder'], $user->id, $uniqueKey, $extension);

        // Create pending file record in MySQL
        $storedFile = StoredFile::create([
            'uuid'              => (string) Str::uuid(),
            'user_id'           => $user->id,
            'entity_type'       => $entityType,
            'entity_id'         => $entityId,
            'original_filename' => $safeOriginalName,
            'storage_disk'      => $this->diskName,
            'storage_path'      => $storagePath,
            'mime_type'         => $mimeType,
            'file_size_bytes'   => $fileSizeBytes,
            'storage_provider'  => 'supabase',
            'upload_status'     => 'pending',
            'metadata'          => $extraMetadata,
        ]);

        $disk = $this->disk();
        $presignedUrl = null;

        // If the disk is an S3 adapter, generate S3 PutObject presigned URL
        if (method_exists($disk, 'getClient')) {
            $client = $disk->getClient();
            $bucket = config("filesystems.disks.{$this->diskName}.bucket", 'motafawweq-storage');
            $cmd = $client->getCommand('PutObject', [
                'Bucket'      => $bucket,
                'Key'         => $storagePath,
                'ContentType' => $mimeType,
            ]);
            $request = $client->createPresignedRequest($cmd, '+15 minutes');
            $presignedUrl = (string) $request->getUri();
        } else {
            // For testing or fallback mock environments
            $presignedUrl = url("/api/v1/storage/mock-upload/{$storedFile->uuid}");
        }

        $this->logSecurityEvent('storage.presigned_upload_requested', [
            'user_id'          => $user->id,
            'stored_file_uuid' => $storedFile->uuid,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'mime_type'        => $mimeType,
            'file_size'        => $fileSizeBytes,
        ]);

        return [
            'file_id'            => $storedFile->uuid,
            'upload_url'         => $presignedUrl,
            'storage_path'       => $storagePath,
            'mime_type'          => $mimeType,
            'expires_in_seconds' => 900, // 15 minutes
            'headers'            => [
                'Content-Type' => $mimeType,
            ],
        ];
    }

    /**
     * Confirm that direct upload completed, verify object in Supabase, and link to entity.
     */
    public function confirmUpload(User $user, string $fileUuid): StoredFile
    {
        $storedFile = StoredFile::where('uuid', $fileUuid)->first();

        if (!$storedFile) {
            throw ValidationException::withMessages([
                'file_id' => [__('messages.not_found')],
            ]);
        }

        // Ownership enforcement
        if ($storedFile->user_id !== $user->id && $user->role !== UserRole::ADMIN) {
            $this->logSecurityEvent('storage.unauthorized_confirm_attempt', [
                'user_id'          => $user->id,
                'stored_file_uuid' => $fileUuid,
            ]);
            throw ValidationException::withMessages([
                'file_id' => [__('messages.unauthorized_action')],
            ]);
        }

        $disk = $this->disk();

        // Verify object existence in Supabase Storage
        if (!$disk->exists($storedFile->storage_path)) {
            $storedFile->update(['upload_status' => 'failed']);
            throw ValidationException::withMessages([
                'file_id' => ['لم يتم العثور على الملف في وحدة التخزين السحابي. يرجى إعادة المحاولة.'],
            ]);
        }

        // Retrieve actual size from storage
        try {
            $actualSize = $disk->size($storedFile->storage_path);
            if ($actualSize > self::MAX_FILE_SIZE_BYTES) {
                $disk->delete($storedFile->storage_path);
                $storedFile->update(['upload_status' => 'failed']);
                throw ValidationException::withMessages([
                    'file_id' => ['حجم الملف المرفوع يتجاوز الحد الأقصى المسموح به (50 ميجابايت).'],
                ]);
            }
            $storedFile->file_size_bytes = $actualSize;
        } catch (\Throwable $e) {
            // size lookup fallback
        }

        $storedFile->upload_status = 'completed';
        $storedFile->save();

        // Apply entity updates
        $this->attachFileToEntity($user, $storedFile);

        $this->logSecurityEvent('storage.upload_confirmed', [
            'user_id'          => $user->id,
            'stored_file_uuid' => $storedFile->uuid,
            'entity_type'      => $storedFile->entity_type,
            'entity_id'        => $storedFile->entity_id,
            'size'             => $storedFile->file_size_bytes,
        ]);

        return $storedFile;
    }

    /**
     * Direct upload from Laravel controller (for fallback, small files, or server-side flows).
     */
    public function storeUploadedFile(
        User $user,
        UploadedFile $file,
        string $entityType,
        ?string $entityId = null,
        array $extraMetadata = []
    ): StoredFile {
        $realPath = $file->getRealPath();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $realPath ? $finfo->file($realPath) : $file->getMimeType();
        $fileSize = (int) $file->getSize();

        $this->validateUploadRequest($user, $entityType, $entityId, $detectedMime, $fileSize);

        $config = $this->entityConfigs[$entityType];
        $extension = $this->resolveExtension($file->getClientOriginalName(), $detectedMime);
        $uniqueKey = Str::uuid()->toString();
        $storagePath = sprintf('%s/%s_%s.%s', $config['folder'], $user->id, $uniqueKey, $extension);

        // Upload to Supabase Storage disk
        $this->disk()->put($storagePath, file_get_contents($realPath));

        $storedFile = StoredFile::create([
            'uuid'              => (string) Str::uuid(),
            'user_id'           => $user->id,
            'entity_type'       => $entityType,
            'entity_id'         => $entityId,
            'original_filename' => $this->sanitizeFilename($file->getClientOriginalName()),
            'storage_disk'      => $this->diskName,
            'storage_path'      => $storagePath,
            'mime_type'         => $detectedMime,
            'file_size_bytes'   => $fileSize,
            'storage_provider'  => 'supabase',
            'upload_status'     => 'completed',
            'metadata'          => $extraMetadata,
        ]);

        $this->attachFileToEntity($user, $storedFile);

        return $storedFile;
    }

    /**
     * Generate a temporary signed download URL for private files.
     */
    public function getTemporaryDownloadUrl(User $user, StoredFile|string $fileOrPath, int $minutes = 60): string
    {
        $path = $fileOrPath instanceof StoredFile ? $fileOrPath->storage_path : $fileOrPath;

        if ($fileOrPath instanceof StoredFile) {
            $this->authorizeAccess($user, $fileOrPath);
        }

        $disk = $this->disk();

        if (method_exists($disk, 'temporaryUrl')) {
            return $disk->temporaryUrl($path, now()->addMinutes($minutes));
        }

        return $disk->url($path);
    }

    /**
     * Delete a stored file from Supabase and database.
     */
    public function deleteStoredFile(User $user, StoredFile $storedFile): void
    {
        if ($storedFile->user_id !== $user->id && $user->role !== UserRole::ADMIN) {
            $this->logSecurityEvent('storage.unauthorized_delete_attempt', [
                'user_id'          => $user->id,
                'stored_file_uuid' => $storedFile->uuid,
            ]);
            throw ValidationException::withMessages([
                'file_id' => [__('messages.unauthorized_action')],
            ]);
        }

        if ($this->disk()->exists($storedFile->storage_path)) {
            $this->disk()->delete($storedFile->storage_path);
        }

        $storedFile->delete();

        $this->logSecurityEvent('storage.file_deleted', [
            'user_id'          => $user->id,
            'stored_file_uuid' => $storedFile->uuid,
            'path'             => $storedFile->storage_path,
        ]);
    }

    /**
     * Validate upload constraints and permissions.
     */
    protected function validateUploadRequest(
        User $user,
        string $entityType,
        ?string $entityId,
        string $mimeType,
        int $fileSizeBytes
    ): void {
        if (!isset($this->entityConfigs[$entityType])) {
            throw ValidationException::withMessages([
                'entity_type' => ['نوع الكيان غير مدعوم في خدمة التخزين.'],
            ]);
        }

        $config = $this->entityConfigs[$entityType];

        // Global Supabase 50 MB Free Plan check
        if ($fileSizeBytes > self::MAX_FILE_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'file_size' => ['حجم الملف يتجاوز الحد الأقصى المسموح به (50 ميجابايت) في باقة التخزين الحالية.'],
            ]);
        }

        // Entity specific size check
        if ($fileSizeBytes > $config['max_size']) {
            $mb = round($config['max_size'] / (1024 * 1024));
            throw ValidationException::withMessages([
                'file_size' => ["حجم الملف يتجاوز الحد الأقصى المسموح لهذا النوع ({$mb} ميجابايت)."],
            ]);
        }

        // MIME type check
        if (!in_array(strtolower($mimeType), $config['allowed_mimes'], true)) {
            throw ValidationException::withMessages([
                'mime_type' => ['نوع الملف غير مسموح به في منصة متفوق.'],
            ]);
        }

        // Authorization checks per entity
        $this->authorizeEntityUpload($user, $entityType, $entityId);
    }

    /**
     * Authorize that the user has permission to upload to the specified entity.
     */
    protected function authorizeEntityUpload(User $user, string $entityType, ?string $entityId): void
    {
        switch ($entityType) {
            case 'user_avatar':
                if ($entityId && (int) $entityId !== $user->id && $user->role !== UserRole::ADMIN) {
                    throw ValidationException::withMessages(['entity_id' => [__('messages.unauthorized_action')]]);
                }
                break;

            case 'lesson_media':
            case 'lesson_attachment':
            case 'lesson_transcript':
            case 'lesson_mindmap':
            case 'lesson_quiz':
                if ($user->role !== UserRole::TEACHER && $user->role !== UserRole::ADMIN) {
                    throw ValidationException::withMessages(['entity_type' => [__('messages.forbidden')]]);
                }
                if ($entityId) {
                    $lesson = Lesson::where('uuid', $entityId)->first();
                    if ($lesson && $lesson->teacher_id !== $user->id && $user->role !== UserRole::ADMIN) {
                        throw ValidationException::withMessages(['entity_id' => [__('messages.unauthorized_action')]]);
                    }
                }
                break;

            case 'course_thumbnail':
            case 'course_resource':
                if ($user->role !== UserRole::TEACHER && $user->role !== UserRole::ADMIN) {
                    throw ValidationException::withMessages(['entity_type' => [__('messages.forbidden')]]);
                }
                if ($entityId) {
                    $course = Course::where('uuid', $entityId)->first();
                    if ($course && $course->teacher_id !== $user->id && $user->role !== UserRole::ADMIN) {
                        throw ValidationException::withMessages(['entity_id' => [__('messages.unauthorized_action')]]);
                    }
                }
                break;

            case 'student_submission':
                if ($user->role !== UserRole::STUDENT && $user->role !== UserRole::ADMIN) {
                    throw ValidationException::withMessages(['entity_type' => [__('messages.forbidden')]]);
                }
                break;
        }
    }

    /**
     * Authorize download access for private files.
     */
    protected function authorizeAccess(User $user, StoredFile $storedFile): void
    {
        // Admin always has access
        if ($user->role === UserRole::ADMIN) {
            return;
        }

        // Owner always has access
        if ($storedFile->user_id === $user->id) {
            return;
        }

        // Teacher accessing student submission in their course/lesson
        if ($storedFile->entity_type === 'student_submission' && $user->role === UserRole::TEACHER) {
            return;
        }

        // Student accessing course materials or lesson attachments
        if (in_array($storedFile->entity_type, ['lesson_media', 'lesson_attachment', 'course_resource'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'file_id' => [__('messages.unauthorized_action')],
        ]);
    }

    /**
     * Update target platform entity once upload is confirmed.
     */
    protected function attachFileToEntity(User $user, StoredFile $storedFile): void
    {
        switch ($storedFile->entity_type) {
            case 'user_avatar':
                $user->update([
                    'avatar' => $this->getTemporaryDownloadUrl($user, $storedFile, 60 * 24 * 7), // 7 days or public signed URL
                ]);
                break;

            case 'lesson_media':
                if ($storedFile->entity_id) {
                    $lesson = Lesson::where('uuid', $storedFile->entity_id)->first();
                    if ($lesson) {
                        // Delete previous media if exists
                        if ($lesson->media_path && $lesson->media_path !== $storedFile->storage_path) {
                            if ($this->disk()->exists($lesson->media_path)) {
                                $this->disk()->delete($lesson->media_path);
                            }
                        }

                        $isAudio = str_starts_with($storedFile->mime_type, 'audio/');
                        $lesson->update([
                            'media_disk'          => $this->diskName,
                            'media_path'          => $storedFile->storage_path,
                            'media_original_name' => $storedFile->original_filename,
                            'media_mime_type'     => $storedFile->mime_type,
                            'media_size_bytes'    => $storedFile->file_size_bytes,
                            'media_mode'          => $isAudio ? LessonMediaMode::AUDIO : LessonMediaMode::VIDEO,
                        ]);
                    }
                }
                break;

            case 'lesson_attachment':
                if ($storedFile->entity_id) {
                    $lesson = Lesson::where('uuid', $storedFile->entity_id)->first();
                    if ($lesson) {
                        $meta = $storedFile->metadata ?? [];
                        $isPdf = $storedFile->mime_type === 'application/pdf';
                        $type = $isPdf ? LessonAttachmentType::PDF : LessonAttachmentType::WHITEBOARD_IMAGE;
                        $nextOrder = (int) $lesson->attachments()->where('type', $type->value)->max('display_order') + 1;

                        LessonAttachment::create([
                            'lesson_id'          => $lesson->id,
                            'teacher_id'         => $lesson->teacher_id,
                            'type'               => $type,
                            'title'              => $meta['title'] ?? pathinfo($storedFile->original_filename, PATHINFO_FILENAME),
                            'caption'            => $meta['caption'] ?? null,
                            'original_file_name' => $storedFile->original_filename,
                            'storage_disk'       => $this->diskName,
                            'storage_path'       => $storedFile->storage_path,
                            'mime_type'          => $storedFile->mime_type,
                            'file_size_bytes'    => $storedFile->file_size_bytes,
                            'file_size_label'    => $this->formatBytes($storedFile->file_size_bytes),
                            'pages_count'        => $isPdf ? (int) ($meta['pages_count'] ?? 1) : null,
                            'display_order'      => $nextOrder,
                        ]);
                    }
                }
                break;

            case 'course_thumbnail':
                if ($storedFile->entity_id) {
                    $course = Course::where('uuid', $storedFile->entity_id)->first();
                    if ($course) {
                        $course->update([
                            'cover_url' => $this->getTemporaryDownloadUrl($user, $storedFile, 60 * 24 * 30),
                        ]);
                    }
                }
                break;

            case 'student_submission':
                if ($storedFile->entity_id) {
                    $submission = HomeworkSubmission::where('uuid', $storedFile->entity_id)->first();
                    if ($submission) {
                        $submission->update([
                            'attachment_name' => $storedFile->original_filename,
                            'attachment_path' => $storedFile->storage_path,
                            'submission_type' => $storedFile->mime_type === 'application/pdf' ? 'pdf' : 'image',
                        ]);
                    }
                }
                break;
        }
    }

    /**
     * Map MIME type or filename to clean file extension.
     */
    protected function resolveExtension(string $filename, string $mimeType): string
    {
        $map = [
            'application/pdf'    => 'pdf',
            'image/jpeg'         => 'jpg',
            'image/png'          => 'png',
            'image/webp'         => 'webp',
            'image/svg+xml'      => 'svg',
            'audio/mpeg'         => 'mp3',
            'audio/mp3'          => 'mp3',
            'audio/wav'          => 'wav',
            'audio/x-wav'        => 'wav',
            'audio/webm'         => 'webm',
            'audio/ogg'          => 'ogg',
            'audio/aac'          => 'aac',
            'audio/m4a'          => 'm4a',
            'audio/x-m4a'        => 'm4a',
            'video/mp4'          => 'mp4',
            'video/webm'         => 'webm',
            'application/json'   => 'json',
            'text/plain'         => 'txt',
        ];

        if (isset($map[$mimeType])) {
            return $map[$mimeType];
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $ext ?: 'bin';
    }

    /**
     * Sanitize original filename to prevent path traversal and unsafe characters.
     */
    protected function sanitizeFilename(string $filename): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s._\-()]/u', '', basename($filename));
        return mb_substr(trim($clean), 0, 180) ?: 'motafawweq_file';
    }

    /**
     * Format bytes into human readable label.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' B';
    }
}

