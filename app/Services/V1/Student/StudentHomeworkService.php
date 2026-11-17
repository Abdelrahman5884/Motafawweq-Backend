<?php

namespace App\Services\V1\Student;

use App\Enums\EnrollmentStatus;
use App\Enums\HomeworkSubmissionStatus;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentHomeworkService extends BaseService
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    /**
     * List all accessible homeworks for the authenticated student with their submission status.
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

        $submissionsByHw = HomeworkSubmission::query()
            ->where('student_id', $student->id)
            ->whereIn('homework_id', $homeworks->pluck('id'))
            ->get()
            ->keyBy('homework_id');

        return $homeworks->map(function (Homework $hw) use ($submissionsByHw) {
            /** @var HomeworkSubmission|null $sub */
            $sub = $submissionsByHw->get($hw->id);
            $status = $sub?->status instanceof HomeworkSubmissionStatus
                ? $sub->status->value
                : ($sub?->status ?? 'not_submitted');

            return [
                'id' => $hw->uuid,
                'uuid' => $hw->uuid,
                'titleAr' => $hw->title_ar,
                'courseTitleAr' => $hw->course_title_ar,
                'lessonTitleAr' => $hw->lesson_title_ar,
                'teacherName' => $hw->teacher?->name,
                'maxGrade' => (int) $hw->max_grade,
                'dueDate' => $hw->due_date?->format('Y-m-d'),
                'dueTime' => $hw->due_time,
                'submissionStatus' => $status,
                'submissionId' => $sub?->uuid,
                'score' => $status === HomeworkSubmissionStatus::GRADED->value ? (float) $sub->score : null,
                'feedbackAr' => $sub?->feedback_ar,
                'submittedAt' => $sub?->submitted_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * Student submits or updates a homework solution (before it is graded).
     */
    public function submitHomework(User $student, string $homeworkUuid, array $data, ?UploadedFile $file = null): HomeworkSubmission
    {
        /** @var Homework|null $homework */
        $homework = Homework::where('uuid', $homeworkUuid)->first();
        if (! $homework) {
            throw (new ModelNotFoundException)->setModel(Homework::class, [$homeworkUuid]);
        }

        $this->accessService->assertCanAccess($student, $homework, (int) $homework->teacher_id, $homework->course_id ? (int) $homework->course_id : null);

        $solutionText = ! empty($data['solution_text']) ? trim(strip_tags((string) $data['solution_text'])) : null;
        if (! $file && empty($solutionText)) {
            throw ValidationException::withMessages([
                'solution' => [__('messages.homework_solution_required')],
            ]);
        }

        // Derive student's center/location strictly from their verified enrollment on the server
        $enrollment = GroupEnrollment::where('student_id', $student->id)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->whereHas('group', fn ($q) => $q->where('teacher_id', $homework->teacher_id))
            ->with('group')
            ->first();

        $locationAr = $enrollment?->group?->center_name ?: 'أكاديمية المتفوق الإلكترونية (أونلاين)';

        return $this->transaction(function () use ($student, $homework, $data, $file, $solutionText, $locationAr) {
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

            $attachmentName = $existing?->attachment_name;
            $attachmentPath = $existing?->attachment_path;
            $submissionType = $data['submission_type'] ?? ($solutionText ? 'text' : 'pdf');

            if ($file) {
                $uploaded = $this->validateAndStoreFile($student, $file, "homeworks/{$homework->uuid}/submissions");

                if ($attachmentPath && Storage::disk('local')->exists($attachmentPath)) {
                    Storage::disk('local')->delete($attachmentPath);
                }

                $attachmentName = $uploaded['name'];
                $attachmentPath = $uploaded['path'];
                $submissionType = $uploaded['file_type'];
            }

            $attributes = [
                'location_ar' => $locationAr,
                'submission_type' => $submissionType,
                'attachment_name' => $attachmentName,
                'attachment_path' => $attachmentPath,
                'solution_text' => $solutionText,
                'status' => HomeworkSubmissionStatus::PENDING,
                'max_score' => (int) $homework->max_grade,
                'submitted_at' => Carbon::now(),
            ];

            if ($existing) {
                $existing->update($attributes);
                $submission = $existing->fresh(['homework', 'student']);
            } else {
                $submission = HomeworkSubmission::create(array_merge([
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
                ],
                'info',
                $submission
            );

            return $submission;
        });
    }

    /**
     * Validate magic-byte MIME and store file in private local disk.
     *
     * @return array{name: string, path: string, file_type: string}
     */
    protected function validateAndStoreFile(User $user, UploadedFile $file, string $subPath): array
    {
        $realPath = $file->getRealPath();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $realPath ? $finfo->file($realPath) : $file->getMimeType();

        if (! in_array($detectedMime, self::ALLOWED_MIMES, true)) {
            $this->logSecurityEvent(
                'student.homework.malicious_upload_blocked',
                [
                    'user_id' => $user->id,
                    'detected_mime' => $detectedMime,
                    'original_name' => $file->getClientOriginalName(),
                ],
                'warning'
            );

            throw ValidationException::withMessages([
                'file' => [__('messages.invalid_homework_file_mime')],
            ]);
        }

        $safeOriginalName = preg_replace('/[^a-zA-Z0-9._\-\x{0600}-\x{06FF} ]/u', '', $file->getClientOriginalName());
        $safeOriginalName = Str::limit(trim($safeOriginalName ?: 'solution'), 150, '');

        $extension = $detectedMime === 'application/pdf' ? 'pdf' : ($file->guessExtension() ?: 'jpg');
        $storedFileName = Str::uuid()->toString().'.'.$extension;
        $storagePath = $file->storeAs($subPath, $storedFileName, 'local');

        return [
            'name' => $safeOriginalName,
            'path' => $storagePath,
            'file_type' => $detectedMime === 'application/pdf' ? 'pdf' : 'image',
        ];
    }
}
