<?php

namespace App\Services\V1\Student;

use App\Enums\EnrollmentStatus;
use App\Enums\HomeworkSubmissionStatus;
use App\Enums\HomeworkType;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\Storage\HomeworkAttachmentStorage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentHomeworkService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService,
        protected HomeworkAttachmentStorage $attachmentStorage
    ) {}

    /**
     * List all accessible homeworks for the authenticated student with complete assignment and submission details.
     */
    public function listHomeworks(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);

        if (empty($entitlements['teacher_ids']) && empty($entitlements['course_ids'])) {
            return [];
        }

        $homeworks = Homework::query()
            ->where(function ($q) use ($entitlements) {
                if (! empty($entitlements['teacher_ids'])) {
                    $q->whereIn('teacher_id', $entitlements['teacher_ids']);
                }
                if (! empty($entitlements['course_ids'])) {
                    $q->orWhereIn('course_id', $entitlements['course_ids']);
                }
            })
            ->with(['teacher:id,name'])
            ->latest()
            ->get();

        if ($homeworks->isEmpty()) {
            return [];
        }

        $submissionsByHw = HomeworkSubmission::query()
            ->where('student_id', $student->id)
            ->whereIn('homework_id', $homeworks->pluck('id'))
            ->get()
            ->keyBy('homework_id');

        $now = Carbon::now();
        $signedExpiry = $now->copy()->addHours(2);

        return $homeworks->map(function (Homework $hw) use ($submissionsByHw, $signedExpiry) {
            /** @var HomeworkSubmission|null $sub */
            $sub = $submissionsByHw->get($hw->id);
            $status = $sub?->status instanceof HomeworkSubmissionStatus
                ? $sub->status->value
                : ($sub?->status ?? 'not_submitted');

            $type = $hw->type instanceof HomeworkType
                ? $hw->type->value
                : (string) ($hw->type ?? ($hw->attachment_path ? 'file' : 'text'));

            $hwDisk = $hw->attachment_disk ?: 'local';
            $hasTeacherAttachment = ! empty($hw->attachment_path)
                && ($hwDisk !== 'local' || Storage::disk('local')->exists($hw->attachment_path));
            $attachmentUrl = null;
            $attachmentDownloadUrl = null;
            $attachmentSizeBytes = $hw->attachment_size_bytes ? (int) $hw->attachment_size_bytes : null;
            if ($hasTeacherAttachment) {
                if ($attachmentSizeBytes === null && $hwDisk === 'local') {
                    $attachmentSizeBytes = (int) Storage::disk('local')->size($hw->attachment_path);
                }
                $attachmentUrl = URL::temporarySignedRoute(
                    'api.v1.student.homeworks.attachment.stream',
                    $signedExpiry,
                    ['uuid' => $hw->uuid]
                );
                $attachmentDownloadUrl = URL::temporarySignedRoute(
                    'api.v1.student.homeworks.attachment.stream',
                    $signedExpiry,
                    ['uuid' => $hw->uuid, 'download' => 1]
                );
            }

            $subDisk = $sub?->attachment_disk ?: 'local';
            $hasSubmissionAttachment = ! empty($sub?->attachment_path)
                && ($subDisk !== 'local' || Storage::disk('local')->exists($sub->attachment_path));
            $submittedAttachmentUrl = null;
            $submittedAttachmentDownloadUrl = null;
            if ($hasSubmissionAttachment) {
                $submittedAttachmentUrl = URL::temporarySignedRoute(
                    'api.v1.student.homeworks.submission.attachment.stream',
                    $signedExpiry,
                    ['submissionUuid' => $sub->uuid]
                );
                $submittedAttachmentDownloadUrl = URL::temporarySignedRoute(
                    'api.v1.student.homeworks.submission.attachment.stream',
                    $signedExpiry,
                    ['submissionUuid' => $sub->uuid, 'download' => 1]
                );
            }

            $isOverdue = false;
            if ($hw->due_date && in_array($status, ['not_submitted', HomeworkSubmissionStatus::REJECTED->value], true)) {
                $dueTime = preg_match('/^\d{2}:\d{2}/', (string) $hw->due_time) ? substr((string) $hw->due_time, 0, 5).':59' : '23:59:59';
                try {
                    $deadline = Carbon::parse($hw->due_date->format('Y-m-d').' '.$dueTime);
                    $isOverdue = $deadline->isPast();
                } catch (\Throwable) {
                    $isOverdue = $hw->due_date->copy()->endOfDay()->isPast();
                }
            }

            $showFeedback = in_array($status, [
                HomeworkSubmissionStatus::GRADED->value,
                HomeworkSubmissionStatus::REJECTED->value,
            ], true);

            return [
                'id' => $hw->uuid,
                'uuid' => $hw->uuid,
                'titleAr' => $hw->title_ar,
                'courseTitleAr' => $hw->course_title_ar,
                'lessonTitleAr' => $hw->lesson_title_ar,
                'teacherName' => $hw->teacher?->name,
                'type' => $type,
                'fileType' => $hw->file_type,
                'textContent' => $hw->text_content,
                'attachmentName' => $hasTeacherAttachment ? $hw->attachment_name : null,
                'attachmentSizeBytes' => $attachmentSizeBytes,
                'attachmentUrl' => $attachmentUrl,
                'attachmentDownloadUrl' => $attachmentDownloadUrl,
                'maxGrade' => (int) $hw->max_grade,
                'dueDate' => $hw->due_date?->format('Y-m-d'),
                'dueTime' => $hw->due_time,
                'isOverdue' => $isOverdue,
                'submissionStatus' => $status,
                'submissionId' => $sub?->uuid,
                'submissionType' => $sub?->submission_type,
                'solutionText' => $sub?->solution_text,
                'submittedAttachmentName' => $hasSubmissionAttachment ? $sub->attachment_name : null,
                'submittedAttachmentUrl' => $submittedAttachmentUrl,
                'submittedAttachmentDownloadUrl' => $submittedAttachmentDownloadUrl,
                'score' => $status === HomeworkSubmissionStatus::GRADED->value && $sub?->score !== null
                    ? (float) $sub->score
                    : null,
                'feedbackAr' => $showFeedback ? $sub?->feedback_ar : null,
                'submittedAt' => $sub?->submitted_at?->toIso8601String(),
                'gradedAt' => $sub?->graded_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Retrieve an accessible homework by UUID and enforce student entitlement.
     */
    public function getAccessibleHomework(User $student, string $homeworkUuid): Homework
    {
        /** @var Homework|null $homework */
        $homework = Homework::where('uuid', $homeworkUuid)->first();
        if (! $homework) {
            throw (new ModelNotFoundException)->setModel(Homework::class, [$homeworkUuid]);
        }

        $this->accessService->assertCanAccess(
            $student,
            $homework,
            (int) $homework->teacher_id,
            $homework->course_id ? (int) $homework->course_id : null
        );

        return $homework;
    }

    /**
     * Student submits or updates a homework solution (before it is graded).
     */
    public function submitHomework(User $student, string $homeworkUuid, array $data, ?UploadedFile $file = null): HomeworkSubmission
    {
        $homework = $this->getAccessibleHomework($student, $homeworkUuid);

        $solutionText = ! empty($data['solution_text']) ? trim(strip_tags((string) $data['solution_text'])) : null;
        $removeAttachment = ! empty($data['remove_attachment']);

        // Derive student's center/location strictly from their verified enrollment on the server
        $enrollment = GroupEnrollment::where('student_id', $student->id)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->whereHas('group', fn ($q) => $q->where('teacher_id', $homework->teacher_id))
            ->with('group')
            ->first();

        $locationAr = $enrollment?->group?->center_name ?: 'أكاديمية المتفوق الإلكترونية (أونلاين)';

        return $this->transaction(function () use ($student, $homework, $data, $file, $solutionText, $removeAttachment, $locationAr) {
            /** @var HomeworkSubmission|null $existing */
            $existing = HomeworkSubmission::where('homework_id', $homework->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === HomeworkSubmissionStatus::GRADED) {
                throw ValidationException::withMessages([
                    'homework' => [__('messages.homework_already_graded')],
                ]);
            }

            $submissionUuid = $existing?->uuid ?: (string) Str::uuid();
            $oldDisk = $existing?->attachment_disk ?: 'local';
            $oldPath = $existing?->attachment_path;

            $attachmentName = $existing?->attachment_name;
            $attachmentPath = $existing?->attachment_path;
            $attachmentDisk = $oldDisk;
            $attachmentMime = $existing?->attachment_mime_type;
            $attachmentSize = $existing?->attachment_size_bytes;
            $hasExistingFile = ! empty($attachmentPath) && ! $removeAttachment;

            if (! $file && ! $hasExistingFile && empty($solutionText)) {
                throw ValidationException::withMessages([
                    'solution' => [__('messages.homework_solution_required')],
                ]);
            }

            if ($removeAttachment && ! $file) {
                if ($oldPath) {
                    $this->attachmentStorage->deleteFile($oldDisk, $oldPath);
                }
                $attachmentName = null;
                $attachmentPath = null;
                $attachmentDisk = 'local';
                $attachmentMime = null;
                $attachmentSize = null;
            }

            $submissionType = $attachmentPath
                ? ($existing?->submission_type ?: ($data['submission_type'] ?? 'pdf'))
                : ($data['submission_type'] ?? 'text');

            if ($file) {
                $uploaded = $this->attachmentStorage->storeFile(
                    user: $student,
                    file: $file,
                    subPath: "homeworks/{$homework->uuid}/submissions",
                    entityType: 'student_submission',
                    entityId: $submissionUuid,
                    auditEventOnBlock: 'student.homework.malicious_upload_blocked',
                    errorTranslationKey: 'messages.invalid_homework_file_mime'
                );

                if ($oldPath && $oldPath !== $uploaded['path']) {
                    $this->attachmentStorage->deleteFile($oldDisk, $oldPath);
                }

                $attachmentName = $uploaded['name'];
                $attachmentPath = $uploaded['path'];
                $attachmentDisk = $uploaded['disk'];
                $attachmentMime = $uploaded['mime_type'];
                $attachmentSize = $uploaded['size_bytes'];
                $submissionType = $uploaded['file_type'];
            }

            $attributes = [
                'location_ar' => $locationAr,
                'submission_type' => $submissionType,
                'attachment_name' => $attachmentName,
                'attachment_path' => $attachmentPath,
                'attachment_disk' => $attachmentDisk,
                'attachment_mime_type' => $attachmentMime,
                'attachment_size_bytes' => $attachmentSize,
                'solution_text' => $solutionText,
                'status' => HomeworkSubmissionStatus::PENDING,
                'max_score' => (int) $homework->max_grade,
                'submitted_at' => Carbon::now(),
            ];

            if ($existing && $existing->status === HomeworkSubmissionStatus::REJECTED) {
                $attributes['score'] = null;
                $attributes['feedback_ar'] = null;
                $attributes['graded_at'] = null;
                $attributes['graded_by'] = null;
            }

            if ($existing) {
                $existing->update($attributes);
                $submission = $existing->fresh(['homework', 'student']);
            } else {
                $submission = HomeworkSubmission::create(array_merge([
                    'uuid' => $submissionUuid,
                    'homework_id' => $homework->id,
                    'student_id' => $student->id,
                ], $attributes));
                $submission->load(['homework', 'student']);
            }

            $homework->recalculateMetrics();

            StudentXpLedger::awardOnce(
                $student->id,
                'homework_submitted',
                $submission->id,
                15,
                "تسليم واجب: {$homework->title_ar}"
            );

            $submission->logSecurityEvent(
                'student.homework.submitted',
                [
                    'homework_uuid' => $homework->uuid,
                    'submission_uuid' => $submission->uuid,
                    'submission_type' => $submissionType,
                    'attachment_disk' => $attachmentPath ? $attachmentDisk : null,
                ],
                'info',
                $submission
            );

            return $submission;
        });
    }
}
