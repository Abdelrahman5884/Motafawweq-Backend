<?php

namespace App\Http\Controllers\Api\V1\Storage;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Storage\ConfirmUploadRequest;
use App\Http\Requests\V1\Storage\PresignUploadRequest;
use App\Models\StoredFile;
use App\Services\V1\Storage\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorageController extends BaseApiController
{
    public function __construct(
        protected SupabaseStorageService $storageService
    ) {}

    /**
     * POST /api/v1/storage/presign-upload
     * Authenticate user, validate entity constraints & limits, generate presigned Supabase S3 URL.
     */
    public function presignUpload(PresignUploadRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser();
        $validated = $request->validated();

        $data = $this->storageService->generatePresignedUploadUrl(
            user: $user,
            entityType: $validated['entity_type'],
            entityId: $validated['entity_id'] ?? null,
            filename: $validated['filename'],
            mimeType: $validated['mime_type'],
            fileSizeBytes: (int) $validated['file_size'],
            extraMetadata: $validated['metadata'] ?? []
        );

        return $this->successResponse(
            $data,
            'تم إنشاء رابط الرفع المباشر بنجاح.'
        );
    }

    /**
     * POST /api/v1/storage/confirm-upload
     * Verify object exists in Supabase Storage, update status to completed, attach to entity.
     */
    public function confirmUpload(ConfirmUploadRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser();
        $validated = $request->validated();

        $storedFile = $this->storageService->confirmUpload($user, $validated['file_id']);

        return $this->successResponse([
            'id'                => $storedFile->uuid,
            'entity_type'       => $storedFile->entity_type,
            'entity_id'         => $storedFile->entity_id,
            'original_filename' => $storedFile->original_filename,
            'mime_type'         => $storedFile->mime_type,
            'file_size_bytes'   => $storedFile->file_size_bytes,
            'status'            => $storedFile->upload_status,
            'download_url'      => $this->storageService->getTemporaryDownloadUrl($user, $storedFile, 60),
            'created_at'        => $storedFile->created_at?->toISOString(),
        ], 'تم التحقق من اكتمال الرفع وحفظ بيانات الملف بنجاح.');
    }

    /**
     * GET /api/v1/storage/download-url/{fileUuid}
     * Generate a temporary signed download URL for private files.
     */
    public function getDownloadUrl(Request $request, string $fileUuid): JsonResponse
    {
        $user = $this->authenticatedUser();
        $storedFile = StoredFile::where('uuid', $fileUuid)->firstOrFail();

        $url = $this->storageService->getTemporaryDownloadUrl($user, $storedFile, 60);

        return $this->successResponse([
            'file_id'      => $storedFile->uuid,
            'filename'     => $storedFile->original_filename,
            'mime_type'    => $storedFile->mime_type,
            'download_url' => $url,
            'expires_in'   => 3600,
        ], 'تم إنشاء رابط التنزيل المؤقت بنجاح.');
    }

    /**
     * DELETE /api/v1/storage/files/{fileUuid}
     * Securely delete file from Supabase and database.
     */
    public function destroy(Request $request, string $fileUuid): JsonResponse
    {
        $user = $this->authenticatedUser();
        $storedFile = StoredFile::where('uuid', $fileUuid)->firstOrFail();

        $this->storageService->deleteStoredFile($user, $storedFile);

        return $this->successResponse([], 'تم حذف الملف بنجاح من وحدة التخزين السحابي.');
    }
}

