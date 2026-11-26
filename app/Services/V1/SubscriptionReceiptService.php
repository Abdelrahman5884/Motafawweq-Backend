<?php

namespace App\Services\V1;

use App\Models\CenterSubscription;
use App\Models\StudentSubscription;
use App\Models\TeacherSubscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Private payment receipt storage & signed-URL streaming for platform subscriptions
 * across Student, Teacher, and Center roles.
 */
class SubscriptionReceiptService
{
    public const DISK = 'local';

    /**
     * Store an uploaded payment receipt privately on the local disk.
     *
     * @return array{path: string, disk: string, original_name: string, mime_type: string}
     */
    public function storeReceipt(UploadedFile $file, string $role, int $userId): array
    {
        $safeRole = in_array($role, ['student', 'teacher', 'center'], true) ? $role : 'other';
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'png');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            $ext = 'png';
        }

        $filename = Str::uuid().'.'.$ext;
        $directory = "subscription-receipts/{$safeRole}/{$userId}";
        $storedPath = $file->storeAs($directory, $filename, self::DISK);

        return [
            'path' => (string) $storedPath,
            'disk' => self::DISK,
            'original_name' => mb_substr(trim((string) $file->getClientOriginalName()), 0, 240) ?: $filename,
            'mime_type' => (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'),
        ];
    }

    /**
     * Generate a temporary signed URL allowing Super Admin to inspect a receipt privately.
     */
    public function signedUrl(string $role, string $uuid, ?string $receiptPath): ?string
    {
        if (! $receiptPath || trim($receiptPath) === '') {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.v1.admin.subscriptions.receipt.stream',
            now()->addMinutes(90),
            ['role' => $role, 'uuid' => $uuid]
        );
    }

    /**
     * Stream a stored subscription receipt file securely.
     */
    public function streamReceipt(string $role, string $uuid): Response
    {
        $subscription = match ($role) {
            'student' => StudentSubscription::where('uuid', $uuid)->first(),
            'teacher' => TeacherSubscription::where('uuid', $uuid)->first(),
            'center' => CenterSubscription::where('uuid', $uuid)->first(),
            default => null,
        };

        if (! $subscription || ! $subscription->receipt_path) {
            abort(404, 'إيصال الدفع غير موجود.');
        }

        $disk = $subscription->receipt_disk ?: self::DISK;
        if (! Storage::disk($disk)->exists($subscription->receipt_path)) {
            abort(404, 'ملف إيصال الدفع غير متاح.');
        }

        $filename = $subscription->receipt_original_name ?: basename($subscription->receipt_path);
        $cleanName = str_replace(["\r", "\n", '"', '\\'], '', trim($filename)) ?: 'receipt';
        $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $cleanName) ?: 'receipt';
        $mime = $subscription->receipt_mime_type ?: (Storage::disk($disk)->mimeType($subscription->receipt_path) ?: 'application/octet-stream');

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => sprintf('inline; filename="%s"; filename*=UTF-8\'\'%s', $asciiFallback, rawurlencode($cleanName)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=1800',
        ];

        if ($disk === 'local' || $disk === 'public') {
            return response()->file(Storage::disk($disk)->path($subscription->receipt_path), $headers);
        }

        return response(Storage::disk($disk)->get($subscription->receipt_path), 200, $headers);
    }
}
