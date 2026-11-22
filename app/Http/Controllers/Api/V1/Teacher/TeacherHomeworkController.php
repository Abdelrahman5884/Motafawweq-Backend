<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\GradeHomeworkSubmissionRequest;
use App\Http\Requests\V1\Teacher\StoreHomeworkRequest;
use App\Http\Requests\V1\Teacher\UpdateHomeworkRequest;
use App\Http\Resources\V1\Teacher\TeacherHomeworkResource;
use App\Http\Resources\V1\Teacher\TeacherHomeworkSubmissionResource;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Services\V1\Storage\HomeworkAttachmentStorage;
use App\Services\V1\Teacher\TeacherHomeworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TeacherHomeworkController extends BaseApiController
{
    public function __construct(
        protected TeacherHomeworkService $homeworkService,
        protected HomeworkAttachmentStorage $attachmentStorage
    ) {}

    /**
     * GET /api/v1/teacher/homeworks
     * List all homework assignments for the teacher.
     */
    public function index(Request $request): JsonResponse
    {
        $homeworks = $this->homeworkService->listHomeworks(
            $this->authenticatedUser(),
            $request->only(['search', 'q', 'course_id', 'target_location_id', 'location', 'type'])
        );

        return $this->successResponse(
            TeacherHomeworkResource::collection($homeworks),
            __('messages.homeworks_fetched'),
            meta: ['total' => $homeworks->count()]
        );
    }

    /**
     * POST /api/v1/teacher/homeworks
     * Create a new homework with optional file attachment uploaded to cloud/server storage.
     */
    public function store(StoreHomeworkRequest $request): JsonResponse
    {
        @set_time_limit(180);
        $file = $request->file('file') ?? $request->file('attachment');

        $homework = $this->homeworkService->createHomework(
            $this->authenticatedUser(),
            $request->validated(),
            $file
        );

        return $this->createdResponse(
            new TeacherHomeworkResource($homework),
            __('messages.homework_created')
        );
    }

    /**
     * GET /api/v1/teacher/homeworks/{uuid}
     * Retrieve single homework details.
     */
    public function show(string $uuid): JsonResponse
    {
        $homework = $this->homeworkService->getHomework(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherHomeworkResource($homework),
            __('messages.homework_fetched')
        );
    }

    /**
     * PUT /api/v1/teacher/homeworks/{uuid}
     * Update an existing homework (also supports POST + _method=PUT for multipart file replacement).
     */
    public function update(UpdateHomeworkRequest $request, string $uuid): JsonResponse
    {
        @set_time_limit(180);
        $file = $request->file('file') ?? $request->file('attachment');

        $homework = $this->homeworkService->updateHomework(
            $this->authenticatedUser(),
            $uuid,
            $request->validated(),
            $file
        );

        return $this->successResponse(
            new TeacherHomeworkResource($homework),
            __('messages.homework_updated')
        );
    }

    /**
     * DELETE /api/v1/teacher/homeworks/{uuid}
     * Soft-delete a homework assignment.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->homeworkService->deleteHomework(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            null,
            __('messages.homework_deleted')
        );
    }

    /**
     * GET /api/v1/teacher/homeworks/{uuid}/submissions
     * List all student submissions for this homework.
     */
    public function submissions(Request $request, string $uuid): JsonResponse
    {
        $result = $this->homeworkService->listSubmissions(
            $this->authenticatedUser(),
            $uuid,
            $request->only(['search', 'q', 'status', 'location_ar', 'location'])
        );

        return $this->successResponse(
            TeacherHomeworkSubmissionResource::collection($result['submissions']),
            __('messages.homework_submissions_fetched'),
            meta: [
                'total' => $result['submissions']->count(),
                'totalAssigned' => (int) $result['homework']->total_assigned,
                'submittedCount' => (int) $result['homework']->submitted_count,
                'gradedCount' => (int) $result['homework']->graded_count,
                'pendingGrading' => (int) $result['homework']->pending_grading,
                'avgGrade' => (float) $result['homework']->avg_grade,
            ]
        );
    }

    /**
     * GET /api/v1/teacher/homeworks/{uuid}/submissions/{submissionUuid}
     * Show single student submission details.
     */
    public function submission(string $uuid, string $submissionUuid): JsonResponse
    {
        $submission = $this->homeworkService->getSubmission(
            $this->authenticatedUser(),
            $uuid,
            $submissionUuid
        );

        return $this->successResponse(
            new TeacherHomeworkSubmissionResource($submission),
            __('messages.homework_submission_fetched')
        );
    }

    /**
     * PATCH /api/v1/teacher/homeworks/{uuid}/submissions/{submissionUuid}/grade
     * Grade or reject a student submission with feedback and score.
     */
    public function gradeSubmission(GradeHomeworkSubmissionRequest $request, string $uuid, string $submissionUuid): JsonResponse
    {
        $submission = $this->homeworkService->gradeSubmission(
            $this->authenticatedUser(),
            $uuid,
            $submissionUuid,
            $request->validated()
        );

        $hw = $submission->homework;

        return $this->successResponse(
            new TeacherHomeworkSubmissionResource($submission),
            __('messages.homework_submission_graded'),
            meta: $hw ? [
                'totalAssigned' => (int) $hw->total_assigned,
                'submittedCount' => (int) $hw->submitted_count,
                'gradedCount' => (int) $hw->graded_count,
                'pendingGrading' => (int) $hw->pending_grading,
                'avgGrade' => (float) $hw->avg_grade,
            ] : []
        );
    }

    /**
     * GET /api/v1/teacher/homeworks/{uuid}/attachment
     * Authenticated teacher streams or downloads homework attachment file.
     */
    public function downloadAttachment(Request $request, string $uuid): Response
    {
        $homework = $this->homeworkService->getHomework(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->attachmentStorage->streamResponse(
            declaredDisk: $homework->attachment_disk,
            path: $homework->attachment_path,
            fallbackBase: 'homework-attachment',
            originalName: $homework->attachment_name,
            declaredMime: $homework->attachment_mime_type,
            forceDownload: $request->boolean('download')
        );
    }

    /**
     * GET /api/v1/media/teacher-homeworks/{uuid}/attachment/stream (temporary signed URL)
     * Stream or download teacher homework attachment directly for inline browser preview/download.
     */
    public function streamAttachment(Request $request, string $uuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        /** @var Homework $homework */
        $homework = Homework::where('uuid', $uuid)->firstOrFail();

        return $this->attachmentStorage->streamResponse(
            declaredDisk: $homework->attachment_disk,
            path: $homework->attachment_path,
            fallbackBase: 'homework-attachment',
            originalName: $homework->attachment_name,
            declaredMime: $homework->attachment_mime_type,
            forceDownload: $request->boolean('download')
        );
    }

    /**
     * GET /api/v1/teacher/homeworks/submissions/{submissionUuid}/attachment
     * Authenticated teacher streams or downloads student submission attachment file.
     */
    public function downloadSubmissionAttachment(Request $request, string $submissionUuid): Response
    {
        $teacher = $this->authenticatedUser();

        /** @var HomeworkSubmission $submission */
        $submission = HomeworkSubmission::where('uuid', $submissionUuid)
            ->with('homework')
            ->firstOrFail();

        $submission->homework->assertOwnedBy($teacher);

        return $this->attachmentStorage->streamResponse(
            declaredDisk: $submission->attachment_disk,
            path: $submission->attachment_path,
            fallbackBase: 'student-solution',
            originalName: $submission->attachment_name,
            declaredMime: $submission->attachment_mime_type,
            forceDownload: $request->boolean('download')
        );
    }

    /**
     * GET /api/v1/media/teacher-homeworks/submissions/{submissionUuid}/attachment/stream (temporary signed URL)
     * Stream or download student submission attachment for inline browser preview/download on teacher grading desk.
     */
    public function streamSubmissionAttachment(Request $request, string $submissionUuid): Response
    {
        if (! $request->hasValidSignature()) {
            return $this->forbiddenResponse(__('messages.invalid_signed_url'));
        }

        /** @var HomeworkSubmission $submission */
        $submission = HomeworkSubmission::where('uuid', $submissionUuid)->firstOrFail();

        return $this->attachmentStorage->streamResponse(
            declaredDisk: $submission->attachment_disk,
            path: $submission->attachment_path,
            fallbackBase: 'student-solution',
            originalName: $submission->attachment_name,
            declaredMime: $submission->attachment_mime_type,
            forceDownload: $request->boolean('download')
        );
    }
}
