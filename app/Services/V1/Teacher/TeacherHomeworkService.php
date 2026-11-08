<?php

namespace App\Services\V1\Teacher;

use App\Enums\HomeworkSubmissionStatus;
use App\Enums\HomeworkType;
use App\Models\Course;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Repositories\Contracts\HomeworkRepositoryInterface;
use App\Repositories\Contracts\HomeworkSubmissionRepositoryInterface;
use App\Services\BaseService;
use Carbon\Carbon;
use finfo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherHomeworkService extends BaseService
{
    public function __construct(
        protected HomeworkRepositoryInterface $homeworkRepository,
        protected HomeworkSubmissionRepositoryInterface $submissionRepository
    ) {}

    /**
     * List all homeworks created by the teacher with filters.
     */
    public function listHomeworks(User $teacher, array $filters = []): Collection
    {
        return $this->homeworkRepository->getForTeacher($teacher->id, $filters);
    }

    /**
     * Retrieve single homework details.
     */
    public function getHomework(User $teacher, string $uuid): Homework
    {
        return $this->homeworkRepository->findByUuidForTeacher($uuid, $teacher);
    }

    /**
     * Create a new homework with optional file attachment or text assignment.
     */
    public function createHomework(User $teacher, array $data, ?UploadedFile $file = null): Homework
    {
        // Resolve Course if provided
        $courseId = null;
        $courseTitle = trim((string) ($data['course_title_ar'] ?? ''));
        if (!empty($data['course_id'])) {
            $course = Course::where('uuid', $data['course_id'])
                ->orWhere('id', is_numeric($data['course_id']) ? (int) $data['course_id'] : 0)
                ->first();
            if ($course) {
                $course->assertOwnedBy($teacher);
                $courseId = $course->id;
                $courseTitle = $courseTitle ?: $course->title_ar;
            }
        }

        // Resolve Lesson if provided
        $lessonId = null;
        $lessonTitle = trim((string) ($data['lesson_title_ar'] ?? ''));
        if (!empty($data['lesson_id'])) {
            $lesson = Lesson::where('uuid', $data['lesson_id'])
                ->orWhere('id', is_numeric($data['lesson_id']) ? (int) $data['lesson_id'] : 0)
                ->first();
            if ($lesson) {
                $lesson->assertOwnedBy($teacher);
                $lessonId = $lesson->id;
                $lessonTitle = $lessonTitle ?: $lesson->title_ar;
            }
        }

        // Handle File Attachment if present
        $attachmentName = null;
        $attachmentPath = null;
        $fileType = null;
        if ($file) {
            $stored = $this->validateAndStoreFile($teacher, $file, "homeworks/{$teacher->id}/teacher_attachments");
            $attachmentPath = $stored['path'];
            $attachmentName = $stored['name'];
            $fileType = $stored['file_type'];
        }

        return $this->transaction(function () use (
            $teacher, $data, $courseId, $courseTitle, $lessonId, $lessonTitle,
            $attachmentName, $attachmentPath, $fileType
        ) {
            $typeStr = strtolower(trim((string) ($data['type'] ?? ($attachmentPath ? 'file' : 'text'))));
            $type = HomeworkType::tryFrom($typeStr) ?? HomeworkType::FILE;

            $maxGrade = max(1, (int) ($data['max_grade'] ?? 20));
            $dueDate = !empty($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null;
            $dueTime = !empty($data['due_time']) ? trim((string) $data['due_time']) : '23:59';
            $targetLocationId = !empty($data['target_location_id']) ? trim((string) $data['target_location_id']) : 'all';
            $targetLocationAr = !empty($data['target_location_ar']) ? trim((string) $data['target_location_ar']) : 'جميع الأماكن (كل السناتر + الأونلاين)';

            // Auto-calculate total assigned students if not explicitly given
            $totalAssigned = isset($data['total_assigned']) ? (int) $data['total_assigned'] : 0;
            if ($totalAssigned === 0) {
                $totalAssigned = GroupEnrollment::whereHas('group', fn ($q) => $q->where('teacher_id', $teacher->id))
                    ->where('status', 'enrolled')
                    ->count();
            }

            /** @var Homework $homework */
            $homework = Homework::create([
                'teacher_id'         => $teacher->id,
                'course_id'          => $courseId,
                'course_title_ar'    => $courseTitle ?: 'المقرر الدراسي',
                'lesson_id'          => $lessonId,
                'lesson_title_ar'    => $lessonTitle,
                'title_ar'           => trim(strip_tags((string) ($data['title_ar'] ?? 'واجب جديد'))),
                'target_location_id' => $targetLocationId,
                'target_location_ar' => $targetLocationAr,
                'type'               => $type,
                'file_type'          => $fileType,
                'attachment_name'    => $attachmentName,
                'attachment_path'    => $attachmentPath,
                'text_content'       => !empty($data['text_content']) ? trim((string) $data['text_content']) : null,
                'due_date'           => $dueDate,
                'due_time'           => $dueTime,
                'max_grade'          => $maxGrade,
                'total_assigned'     => $totalAssigned,
            ]);

            $this->logSecurityEvent(
                'homework_created',
                [
                    'teacher_id'    => $teacher->id,
                    'homework_uuid' => $homework->uuid,
                    'title_ar'      => $homework->title_ar,
                ],
                'info',
                $homework
            );

            return $homework->load(['course', 'lesson']);
        });
    }

    /**
     * Update an existing homework.
     */
    public function updateHomework(User $teacher, string $uuid, array $data, ?UploadedFile $file = null): Homework
    {
        $homework = $this->homeworkRepository->findByUuidForTeacher($uuid, $teacher);

        // Resolve Course if provided
        $courseId = $homework->course_id;
        $courseTitle = $homework->course_title_ar;
        if (array_key_exists('course_id', $data)) {
            if (!empty($data['course_id'])) {
                $course = Course::where('uuid', $data['course_id'])
                    ->orWhere('id', is_numeric($data['course_id']) ? (int) $data['course_id'] : 0)
                    ->first();
                if ($course) {
                    $course->assertOwnedBy($teacher);
                    $courseId = $course->id;
                    $courseTitle = $course->title_ar;
                }
            } else {
                $courseId = null;
                $courseTitle = null;
            }
        }

        // Resolve Lesson if provided
        $lessonId = $homework->lesson_id;
        $lessonTitle = $homework->lesson_title_ar;
        if (array_key_exists('lesson_id', $data)) {
            if (!empty($data['lesson_id'])) {
                $lesson = Lesson::where('uuid', $data['lesson_id'])
                    ->orWhere('id', is_numeric($data['lesson_id']) ? (int) $data['lesson_id'] : 0)
                    ->first();
                if ($lesson) {
                    $lesson->assertOwnedBy($teacher);
                    $lessonId = $lesson->id;
                    $lessonTitle = $lesson->title_ar;
                }
            } else {
                $lessonId = null;
                $lessonTitle = null;
            }
        }

        return $this->transaction(function () use ($teacher, $homework, $data, $courseId, $courseTitle, $lessonId, $lessonTitle, $file) {
            $attachmentName = $homework->attachment_name;
            $attachmentPath = $homework->attachment_path;
            $fileType = $homework->file_type;

            if ($file) {
                // Delete old file if present
                if ($attachmentPath && Storage::disk('local')->exists($attachmentPath)) {
                    Storage::disk('local')->delete($attachmentPath);
                }
                $stored = $this->validateAndStoreFile($teacher, $file, "homeworks/{$teacher->id}/teacher_attachments");
                $attachmentPath = $stored['path'];
                $attachmentName = $stored['name'];
                $fileType = $stored['file_type'];
            }

            $updatePayload = [
                'course_id'       => $courseId,
                'course_title_ar' => $courseTitle,
                'lesson_id'       => $lessonId,
                'lesson_title_ar' => $lessonTitle,
                'attachment_name' => $attachmentName,
                'attachment_path' => $attachmentPath,
                'file_type'       => $fileType,
            ];

            if (isset($data['title_ar'])) {
                $updatePayload['title_ar'] = trim(strip_tags((string) $data['title_ar']));
            }
            if (isset($data['target_location_id'])) {
                $updatePayload['target_location_id'] = trim((string) $data['target_location_id']);
            }
            if (isset($data['target_location_ar'])) {
                $updatePayload['target_location_ar'] = trim(strip_tags((string) $data['target_location_ar']));
            }
            if (isset($data['type'])) {
                $updatePayload['type'] = HomeworkType::tryFrom($data['type']) ?? $homework->type;
            }
            if (array_key_exists('text_content', $data)) {
                $updatePayload['text_content'] = $data['text_content'];
            }
            if (isset($data['due_date'])) {
                $updatePayload['due_date'] = Carbon::parse($data['due_date'])->toDateString();
            }
            if (isset($data['due_time'])) {
                $updatePayload['due_time'] = trim((string) $data['due_time']);
            }
            if (isset($data['max_grade'])) {
                $updatePayload['max_grade'] = max(1, (int) $data['max_grade']);
            }
            if (isset($data['total_assigned'])) {
                $updatePayload['total_assigned'] = max(0, (int) $data['total_assigned']);
            }

            $homework->update($updatePayload);

            $this->logSecurityEvent(
                'homework_updated',
                [
                    'teacher_id'    => $teacher->id,
                    'homework_uuid' => $homework->uuid,
                ],
                'info',
                $homework
            );

            return $homework->fresh(['course', 'lesson']);
        });
    }

    /**
     * Delete a homework.
     */
    public function deleteHomework(User $teacher, string $uuid): bool
    {
        $homework = $this->homeworkRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $homework) {
            $this->logSecurityEvent(
                'homework_deleted',
                [
                    'teacher_id'    => $teacher->id,
                    'homework_uuid' => $homework->uuid,
                    'title_ar'      => $homework->title_ar,
                ],
                'warning',
                $homework
            );

            return (bool) $homework->delete();
        });
    }

    /**
     * List all student submissions for a specific homework with filters and counters.
     *
     * @return array{homework: Homework, submissions: Collection}
     */
    public function listSubmissions(User $teacher, string $homeworkUuid, array $filters = []): array
    {
        $homework = $this->homeworkRepository->findByUuidForTeacher($homeworkUuid, $teacher);
        $submissions = $this->submissionRepository->getSubmissionsForHomework($homework->id, $filters);

        return [
            'homework'    => $homework,
            'submissions' => $submissions,
        ];
    }

    /**
     * Get single submission details under this homework.
     */
    public function getSubmission(User $teacher, string $homeworkUuid, string $submissionUuid): HomeworkSubmission
    {
        $homework = $this->homeworkRepository->findByUuidForTeacher($homeworkUuid, $teacher);

        return $this->submissionRepository->findByUuidForHomework($submissionUuid, $homework->id);
    }

    /**
     * Grade or reject a student submission, award score, record teacher feedback and recalculate homework counters.
     */
    public function gradeSubmission(User $teacher, string $homeworkUuid, string $submissionUuid, array $data): HomeworkSubmission
    {
        $homework = $this->homeworkRepository->findByUuidForTeacher($homeworkUuid, $teacher);
        $submission = $this->submissionRepository->findByUuidForHomework($submissionUuid, $homework->id);

        return $this->transaction(function () use ($teacher, $homework, $submission, $data) {
            $statusStr = strtolower(trim((string) ($data['status'] ?? 'graded')));
            $status = HomeworkSubmissionStatus::tryFrom($statusStr) ?? HomeworkSubmissionStatus::GRADED;

            $maxGrade = (int) $homework->max_grade;
            $score = null;
            if ($status === HomeworkSubmissionStatus::GRADED) {
                $rawScore = isset($data['score']) ? (float) $data['score'] : $maxGrade;
                $score = max(0.0, min((float) $maxGrade, $rawScore));
            } elseif ($status === HomeworkSubmissionStatus::REJECTED) {
                $score = 0.00;
            }

            $feedback = isset($data['feedback_ar']) ? trim(strip_tags((string) $data['feedback_ar'])) : $submission->feedback_ar;

            $submission->update([
                'status'      => $status,
                'score'       => $score,
                'max_score'   => $maxGrade,
                'feedback_ar' => $feedback,
                'graded_at'   => Carbon::now(),
                'graded_by'   => $teacher->id,
            ]);

            // Recalculate parent homework submission metrics
            $homework->recalculateMetrics();

            $submission->logSecurityEvent(
                'homework_submission_graded',
                [
                    'teacher_id'      => $teacher->id,
                    'homework_uuid'   => $homework->uuid,
                    'submission_uuid' => $submission->uuid,
                    'status'          => $status->value,
                    'score'           => $score,
                ],
                'info',
                $submission
            );

            return $submission->fresh(['student', 'grader', 'homework']);
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
