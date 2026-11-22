<?php

namespace App\Services\V1\Storage;

use App\Models\StoredFile;
use App\Models\User;
use App\Services\BaseService;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\StorageFake;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared secure storage helper for Teacher Homework attachments and Student Homework submissions.
 * Uploads to Supabase S3 when configured (with graceful local fallback), validates magic bytes,
 * tracks records in stored_files, and streams files safely from either cloud or local disks.
 */
class HomeworkAttachmentStorage extends BaseService
{
    public const ALLOWED_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Resolve the active storage disk: Supabase S3 when configured, with clean test fake support.
     */
    public function preferredDisk(): string
    {
        if (Storage::disk('supabase') instanceof StorageFake) {
            return 'supabase';
        }

        if (Storage::disk('local') instanceof StorageFake) {
            return 'local';
        }

        $hasSupabase = ! empty(config('filesystems.disks.supabase.key'))
            && ! empty(config('filesystems.disks.supabase.secret'))
            && ! empty(config('filesystems.disks.supabase.endpoint'));

        return $hasSupabase ? 'supabase' : 'local';
    }

    /**
     * Validate magic-byte MIME signature and store an uploaded homework file on cloud/server storage.
     *
     * @return array{
     *   disk: string,
     *   path: string,
     *   name: string,
     *   file_type: string,
     *   mime_type: string,
     *   size_bytes: int,
     *   stored_file_uuid: string
     * }
     */
    public function storeFile(
        User $user,
        UploadedFile $file,
        string $subPath,
        string $entityType,
        ?string $entityId = null,
        string $auditEventOnBlock = 'malicious_file_upload_blocked',
        string $errorTranslationKey = 'messages.invalid_file_type'
    ): array {
        $realPath = $file->getRealPath();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $realPath ? $finfo->file($realPath) : (string) $file->getMimeType();

        $isValidMime = isset(self::ALLOWED_MIMES[$detectedMime]);
        if ($isValidMime && $detectedMime === 'application/pdf' && $realPath) {
            $header = (string) file_get_contents($realPath, false, null, 0, 5);
            if (! str_starts_with($header, '%PDF-')) {
                $isValidMime = false;
            }
        }

        if (! $isValidMime) {
            $this->logSecurityEvent(
                $auditEventOnBlock,
                [
                    'user_id' => $user->id,
                    'detected_mime' => $detectedMime,
                    'filename' => $file->getClientOriginalName(),
                    'entity_type' => $entityType,
                ],
                'warning'
            );

            throw ValidationException::withMessages([
                'file' => [__($errorTranslationKey)],
            ]);
        }

        $extension = self::ALLOWED_MIMES[$detectedMime];
        $storagePath = trim($subPath, '/').'/'.Str::uuid()->toString().".{$extension}";
        $preferredDisk = $this->preferredDisk();
        $actualDisk = $this->writeStreamWithFallback($preferredDisk, $storagePath, $realPath);

        $safeName = $this->sanitizeFilename($file->getClientOriginalName(), $extension);
        $sizeBytes = max(0, (int) $file->getSize());

        $storedFile = StoredFile::updateOrCreate(
            ['storage_path' => $storagePath],
            [
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'original_filename' => $safeName,
                'storage_disk' => $actualDisk,
                'mime_type' => $detectedMime,
                'file_size_bytes' => $sizeBytes,
                'storage_provider' => $actualDisk === 'supabase' ? 'supabase' : 'local',
                'upload_status' => 'completed',
                'metadata' => [
                    'file_type' => $detectedMime === 'application/pdf' ? 'pdf' : 'image',
                ],
            ]
        );

        return [
            'disk' => $actualDisk,
            'path' => $storagePath,
            'name' => $safeName,
            'file_type' => $detectedMime === 'application/pdf' ? 'pdf' : 'image',
            'mime_type' => $detectedMime,
            'size_bytes' => $sizeBytes,
            'stored_file_uuid' => $storedFile->uuid,
        ];
    }

    /**
     * Delete an attachment from its storage disk and soft-delete its StoredFile ledger row.
     */
    public function deleteFile(?string $disk, ?string $path): void
    {
        if (! $path) {
            return;
        }

        $candidateDisks = array_values(array_unique(array_filter([$disk ?: 'local', 'local'])));
        foreach ($candidateDisks as $candidate) {
            try {
                if (Storage::disk($candidate)->exists($path)) {
                    Storage::disk($candidate)->delete($path);
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning('Homework attachment delete skipped on disk', [
                    'disk' => $candidate,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        StoredFile::where('storage_path', $path)->delete();
    }

    /**
     * Resolve which disk currently holds an existing attachment path.
     */
    public function resolveExistingDisk(?string $declaredDisk, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $primary = $declaredDisk ?: 'local';
        $candidates = $primary === 'local' ? ['local'] : [$primary, 'local'];

        foreach ($candidates as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return $disk;
                }
            } catch (\Throwable $e) {
                Log::warning('Homework attachment existence check failed on disk', [
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Stream or download a stored homework file from Supabase S3 or local disk with strict security headers.
     */
    public function streamResponse(
        ?string $declaredDisk,
        ?string $path,
        string $fallbackBase,
        ?string $originalName,
        ?string $declaredMime,
        bool $forceDownload = false
    ): Response {
        $disk = $this->resolveExistingDisk($declaredDisk, $path);
        if (! $disk || ! $path) {
            abort(404, __('messages.file_not_found'));
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $displayName = trim((string) ($originalName ?: $fallbackBase));
        if ($ext && ! str_contains($displayName, '.')) {
            $displayName .= '.'.$ext;
        }

        $asciiFallback = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $displayName) ?: ($fallbackBase.($ext ? '.'.$ext : ''));
        $asciiFallback = trim($asciiFallback, " ._\t\n\r") ?: ($fallbackBase.($ext ? '.'.$ext : ''));
        $dispositionType = $forceDownload ? 'attachment' : 'inline';
        $disposition = sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $dispositionType,
            str_replace(['"', '\\'], '', $asciiFallback),
            rawurlencode($displayName)
        );

        if ($disk === 'local') {
            $absolutePath = Storage::disk('local')->path($path);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $declaredMime ?: ($finfo->file($absolutePath) ?: $this->mimeFromExtension($ext));

            return response()->file($absolutePath, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => $disposition,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        $mimeType = $declaredMime ?: $this->mimeFromExtension($ext);
        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        $stream = Storage::disk($disk)->readStream($path);
        if (is_resource($stream)) {
            return response()->stream(function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }, Response::HTTP_OK, $headers);
        }

        return response(
            (string) Storage::disk($disk)->get($path),
            Response::HTTP_OK,
            $headers
        );
    }

    /**
     * Format byte count into a clean human-readable label.
     */
    public static function formatBytes(?int $bytes): ?string
    {
        if (! $bytes || $bytes <= 0) {
            return null;
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }

    /**
     * Stream file contents to the preferred disk (Supabase S3) with automatic fallback to local private disk.
     */
    protected function writeStreamWithFallback(string $preferredDisk, string $storagePath, string $realPath): string
    {
        if ($preferredDisk === 'supabase') {
            try {
                $stream = fopen($realPath, 'rb');
                if ($stream !== false) {
                    try {
                        $uploaded = Storage::disk('supabase')->put($storagePath, $stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    if ($uploaded) {
                        return 'supabase';
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Supabase homework upload failed; falling back to local disk', [
                    'path' => $storagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $localStream = fopen($realPath, 'rb');
        if ($localStream !== false) {
            try {
                Storage::disk('local')->put($storagePath, $localStream);
            } finally {
                if (is_resource($localStream)) {
                    fclose($localStream);
                }
            }
        } else {
            Storage::disk('local')->put($storagePath, (string) file_get_contents($realPath));
        }

        return 'local';
    }

    protected function sanitizeFilename(string $originalName, string $extension): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9._\-\x{0600}-\x{06FF}() ]/u', '', basename($originalName));
        $clean = Str::limit(trim((string) $clean), 160, '');

        if ($clean === '') {
            return "attachment.{$extension}";
        }

        return $clean;
    }

    protected function mimeFromExtension(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
