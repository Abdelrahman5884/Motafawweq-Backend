<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\SubmitHomeworkRequest;
use App\Http\Resources\V1\Student\StudentHomeworkSubmissionResource;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Services\V1\Student\StudentHomeworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class StudentHomeworkController extends BaseApiController
{
    public function __construct(
        protected StudentHomeworkService $studentHomeworkService
    ) {}

    /**
     * GET /api/v1/student/homeworks
     * List accessible homeworks for the student.
     */
    public function index(): JsonResponse
    {
        $homeworks = $this->studentHomeworkService->listHomeworks($this->authenticatedUser());

        return $this->successResponse($homeworks);
    }

    /**
     * POST /api/v1/student/homeworks/{uuid}/submit (and legacy /api/v1/homeworks/{uuid}/submit)
     * Submit or update homework solution by authenticated student.
     */
    public function submit(SubmitHomeworkRequest $request, string $uuid): JsonResponse
    {
        $file = $request->file('file') ?? $request->file('attachment');

        $submission = $this->studentHomeworkService->submitHomework(
            $this->authenticatedUser(),
            $uuid,
            $request->validated(),
            $file
        );

        return $this->createdResponse(
            new StudentHomeworkSubmissionResource($submission),
            __('messages.homework_submitted_successfully')
        );
    }

    /**
     * GET /api/v1/student/homeworks/{uuid}/attachment
     * Authenticated student downloads teacher's homework assignment attachment.
     */
    public function downloadHomeworkAttachment(Request $request, string $uuid): Response
    {
        $homework = $this->studentHomeworkService->getAccessibleHomework(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->serveLocalFile(
            $homework->attachment_path,
            'homework-sheet',
            $homework->attachment_name,
            $request->boolean('download')
        );
    }

    /**
     * GET /api/v1/media/student-homeworks/{uuid}/attachment/stream (temporary signed URL)
     * Stream or download teacher's homework attachment directly for browser preview/download.
     */
    public function streamHomeworkAttachment(Request $request, string $uuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        /** @var Homework $homework */
        $homework = Homework::where('uuid', $uuid)->firstOrFail();

        return $this->serveLocalFile(
            $homework->attachment_path,
            'homework-sheet',
            $homework->attachment_name,
            $request->boolean('download')
        );
    }

    /**
     * GET /api/v1/student/homeworks/submissions/{submissionUuid}/attachment
     * Student streams their own submitted solution attachment (strictly enforces ownership).
     */
    public function downloadSubmissionAttachment(Request $request, string $submissionUuid): Response
    {
        $student = $this->authenticatedUser();

        /** @var HomeworkSubmission $submission */
        $submission = HomeworkSubmission::where('uuid', $submissionUuid)->firstOrFail();
        $submission->assertOwnedByStudent($student);

        return $this->serveLocalFile(
            $submission->attachment_path,
            'my-solution',
            $submission->attachment_name,
            $request->boolean('download')
        );
    }

    /**
     * GET /api/v1/media/student-homeworks/submissions/{submissionUuid}/attachment/stream (temporary signed URL)
     * Stream or download student's submitted solution attachment for inline preview/download.
     */
    public function streamSubmissionAttachment(Request $request, string $submissionUuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        /** @var HomeworkSubmission $submission */
        $submission = HomeworkSubmission::where('uuid', $submissionUuid)->firstOrFail();

        return $this->serveLocalFile(
            $submission->attachment_path,
            'my-solution',
            $submission->attachment_name,
            $request->boolean('download')
        );
    }

    /**
     * Safely serve a private local disk file with strict MIME detection and RFC 5987 UTF-8 filename headers.
     */
    private function serveLocalFile(?string $path, string $fallbackBase, ?string $originalName, bool $forceDownload): Response
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404, __('messages.file_not_found'));
        }

        $absolutePath = Storage::disk('local')->path($path);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($absolutePath) ?: 'application/octet-stream';

        $displayName = trim((string) ($originalName ?: $fallbackBase));
        $ext = pathinfo($absolutePath, PATHINFO_EXTENSION);
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

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
