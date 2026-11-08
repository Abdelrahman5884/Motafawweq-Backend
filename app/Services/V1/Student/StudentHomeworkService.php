<?php

namespace App\Services\V1\Student;

use App\Enums\HomeworkSubmissionStatus;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use finfo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentHomeworkService extends BaseService
{
    /**
     * Submit homework solution by student (file or text).
     */
    public function submitHomework(User $student, string $homeworkUuid, array $data, ?UploadedFile $file = null): HomeworkSubmission
    {
        /** @var Homework|null $homework */
        $homework = Homework::where('uuid', $homeworkUuid)->first();
        if (!$homework) {
            throw (new ModelNotFoundException())->setModel(Homework::class, [$homeworkUuid]);
        }

        $solutionText = !empty($data['solution_text']) ? trim((string) $data['solution_text']) : null;
        if (!$file && empty($solutionText)) {
            throw ValidationException::withMessages([
                'solution' => [__('messages.homework_solution_required')],
            ]);
        }

        // Check if student already has a submission
        $existing = HomeworkSubmission::where('homework_id', $homework->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existing && $existing->status === HomeworkSubmissionStatus::GRADED) {
            throw ValidationException::withMessages([
                'homework' => [__('messages.homework_already_graded')],
            ]);
        }

        // Determine student's study group center / location if enrolled
        $locationAr = trim((string) ($data['location_ar'] ?? ''));
        if ($locationAr === '') {
            $enrollment = GroupEnrollment::where('student_id', $student->id)
                ->whereHas('group', fn ($q) => $q->where('teacher_id', $homework->teacher_id))
                ->with('group')
                ->first();

            $locationAr = $enrollment?->group?->center_name ?: 'أكاديمية المتفوق الإلكترونية (أونلاين)';
        }

        return $this->transaction(function () use ($student, $homework, $data, $file, $solutionText, $existing, $locationAr) {
            $attachmentName = $existing?->attachment_name;
            $attachmentPath = $existing?->attachment_path;
            $submissionType = $existing?->submission_type ?? 'text';

            if ($file) {
                if ($attachmentPath && Storage::disk('local')->exists($attachmentPath)) {
                    Storage::disk('local')->delete($attachmentPath);
                }

                $stored = $this->validateAndStoreFile($student, $file, "homeworks/submissions/{$student->id}");
                $attachmentPath = $stored['path'];
                $attachmentName = $stored['name'];
                $submissionType = $stored['file_type'];
            } elseif ($solutionText) {
                $submissionType = 'text';
            }

            /** @var HomeworkSubmission $submission */
            $submission = HomeworkSubmission::updateOrCreate(
                [
                    'homework_id' => $homework->id,
                    'student_id'  => $student->id,
                ],
                [
                    'location_ar'     => $locationAr,
                    'submission_type' => $submissionType,
                    'attachment_name' => $attachmentName,
                    'attachment_path' => $attachmentPath,
                    'solution_text'   => $solutionText ?: $existing?->solution_text,
                    'status'          => HomeworkSubmissionStatus::PENDING,
                    'score'           => null,
                    'max_score'       => (int) $homework->max_grade,
                    'submitted_at'    => Carbon::now(),
                ]
            );

            // Recalculate parent homework counters
            $homework->recalculateMetrics();

            $submission->logSecurityEvent(
                'homework_submitted_by_student',
                [
                    'student_id'    => $student->id,
                    'homework_uuid' => $homework->uuid,
                    'type'          => $submissionType,
                ],
                'info',
                $submission
            );

            return $submission->load(['homework', 'student']);
        });
    }

    /**
     * Inspect file magic bytes and securely store it on local disk.
     */
    protected function validateAndStoreFile(User $user, UploadedFile $file, string $subPath): array
    {
        $realPath = $file->getRealPath();
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $realPath ? $finfo->file($realPath) : $file->getMimeType();

        $allowedMimes = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
        ];

        if (!isset($allowedMimes[$detectedMime])) {
            $this->logSecurityEvent(
                'malicious_file_upload_blocked',
                [
                    'user_id'       => $user->id,
                    'detected_mime' => $detectedMime,
                    'filename'      => $file->getClientOriginalName(),
                ],
                'warning'
            );

            throw ValidationException::withMessages([
                'file' => [__('messages.invalid_file_type')],
            ]);
        }

        $extension = $allowedMimes[$detectedMime];
        $storagePath = "{$subPath}/" . Str::uuid() . ".{$extension}";
        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));

        return [
            'path'      => $storagePath,
            'name'      => $file->getClientOriginalName(),
            'file_type' => $extension === 'pdf' ? 'pdf' : 'image',
        ];
    }
}
