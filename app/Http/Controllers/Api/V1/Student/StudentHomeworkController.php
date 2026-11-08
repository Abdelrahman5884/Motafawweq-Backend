<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Student\SubmitHomeworkRequest;
use App\Http\Resources\V1\Student\StudentHomeworkSubmissionResource;
use App\Models\HomeworkSubmission;
use App\Services\V1\Student\StudentHomeworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentHomeworkController extends BaseApiController
{
    public function __construct(
        protected StudentHomeworkService $studentHomeworkService
    ) {}

    /**
     * POST /api/v1/homeworks/{uuid}/submit
     * Submit homework solution by authenticated student.
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
     * GET /api/v1/homeworks/submissions/{submissionUuid}/attachment
     * Student streams their own submitted solution attachment.
     */
    public function downloadSubmissionAttachment(string $submissionUuid): BinaryFileResponse
    {
        $student = $this->authenticatedUser();

        /** @var HomeworkSubmission $submission */
        $submission = HomeworkSubmission::where('uuid', $submissionUuid)
            ->where('student_id', $student->id)
            ->firstOrFail();

        if (!$submission->attachment_path || !Storage::disk('local')->exists($submission->attachment_path)) {
            abort(404, __('messages.file_not_found'));
        }

        return response()->file(
            Storage::disk('local')->path($submission->attachment_path),
            ['Content-Disposition' => 'inline; filename="' . ($submission->attachment_name ?: 'my-solution') . '"']
        );
    }
}

