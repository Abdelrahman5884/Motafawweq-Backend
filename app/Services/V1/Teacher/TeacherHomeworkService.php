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
use App\Services\V1\Storage\HomeworkAttachmentStorage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class TeacherHomeworkService extends BaseService
{
    public function __construct(
        protected HomeworkRepositoryInterface $homeworkRepository,
        protected HomeworkSubmissionRepositoryInterface $submissionRepository,
        protected HomeworkAttachmentStorage $attachmentStorage
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
     * Create a new homework with optional cloud-stored file attachment and/or text assignment.
     */
    public function createHomework(User $teacher, array $data, ?UploadedFile $file = null): Homework
    {
        // Resolve Course if provided
        $courseId = null;
        $courseTitle = trim((string) ($data['course_title_ar'] ?? ''));
        if (! empty($data['course_id'])) {
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
        if (! empty($data['lesson_id'])) {
            $lesson = Lesson::where('uuid', $data['lesson_id'])
                ->orWhere('id', is_numeric($data['lesson_id']) ? (int) $data['lesson_id'] : 0)
                ->first();
            if ($lesson) {
                $lesson->assertOwnedBy($teacher);
                $lessonId = $lesson->id;
                $lessonTitle = $lessonTitle ?: $lesson->title_ar;
            }
        }

        $homeworkUuid = (string) Str::uuid();

        // Handle File Attachment via Supabase S3 (with automatic local fallback)
        $attachmentName = null;
        $attachmentPath = null;
        $attachmentDisk = 'local';
        $attachmentMime = null;
        $attachmentSize = null;
        $fileType = null;

        if ($file) {
            $stored = $this->attachmentStorage->storeFile(
                user: $teacher,
                file: $file,
                subPath: "homeworks/{$teacher->id}/teacher_attachments",
                entityType: 'homework_attachment',
                entityId: $homeworkUuid
            );
            $attachmentPath = $stored['path'];
            $attachmentDisk = $stored['disk'];
            $attachmentName = $stored['name'];
            $attachmentMime = $stored['mime_type'];
            $attachmentSize = $stored['size_bytes'];
            $fileType = $stored['file_type'];
        }

        return $this->transaction(function () use (
            $teacher, $homeworkUuid, $data, $courseId, $courseTitle, $lessonId, $lessonTitle,
            $attachmentName, $attachmentPath, $attachmentDisk, $attachmentMime, $attachmentSize, $fileType
        ) {
            $typeStr = strtolower(trim((string) ($data['type'] ?? ($attachmentPath ? 'file' : 'text'))));
            $type = HomeworkType::tryFrom($typeStr) ?? ($attachmentPath ? HomeworkType::FILE : HomeworkType::TEXT);

            $maxGrade = max(1, (int) ($data['max_grade'] ?? 20));
            $dueDate = ! empty($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null;
            $dueTime = ! empty($data['due_time']) ? trim((string) $data['due_time']) : '23:59';
            $targetLocationId = ! empty($data['target_location_id']) ? trim((string) $data['target_location_id']) : 'all';
            $targetLocationAr = ! empty($data['target_location_ar']) ? trim((string) $data['target_location_ar']) : 'جميع الأماكن (كل السناتر + الأونلاين)';

            // Auto-calculate total unique assigned students if not explicitly given
            $totalAssigned = isset($data['total_assigned']) ? (int) $data['total_assigned'] : 0;
            if ($totalAssigned === 0) {
                $totalAssigned = GroupEnrollment::whereHas('group', fn ($q) => $q->where('teacher_id', $teacher->id))
                    ->where('status', 'enrolled')
                    ->distinct('student_id')
                    ->count('student_id');
            }

            /** @var Homework $homework */
            $homework = Homework::create([
                'uuid' => $homeworkUuid,
                'teacher_id' => $teacher->id,
                'course_id' => $courseId,
                'course_title_ar' => $courseTitle ?: 'المقرر الدراسي',
                'lesson_id' => $lessonId,
                'lesson_title_ar' => $lessonTitle,
                'title_ar' => trim(strip_tags((string) ($data['title_ar'] ?? 'واجب جديد'))),
                'target_location_id' => $targetLocationId,
                'target_location_ar' => $targetLocationAr,
                'type' => $type,
                'file_type' => $fileType,
                'attachment_name' => $attachmentName,
                'attachment_path' => $attachmentPath,
                'attachment_disk' => $attachmentDisk,
                'attachment_mime_type' => $attachmentMime,
                'attachment_size_bytes' => $attachmentSize,
                'text_content' => ! empty($data['text_content']) ? trim((string) $data['text_content']) : null,
                'due_date' => $dueDate,
                'due_time' => $dueTime,
                'max_grade' => $maxGrade,
                'total_assigned' => $totalAssigned,
            ]);

            $this->logSecurityEvent(
                'homework_created',
                [
                    'teacher_id' => $teacher->id,
                    'homework_uuid' => $homework->uuid,
                    'title_ar' => $homework->title_ar,
                    'attachment_disk' => $attachmentPath ? $attachmentDisk : null,
                ],
                'info',
                $homework
            );

            return $homework->load(['course', 'lesson']);
        });
    }

    /**
     * Update an existing homework (metadata and/or cloud-stored attachment).
     */
    public function updateHomework(User $teacher, string $uuid, array $data, ?UploadedFile $file = null): Homework
    {
        $homework = $this->homeworkRepository->findByUuidForTeacher($uuid, $teacher);

        // Resolve Course if provided
        $courseId = $homework->course_id;
        $courseTitle = $homework->course_title_ar;
        if (array_key_exists('course_id', $data)) {
            if (! empty($data['course_id'])) {
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
                $courseTitle = ! empty($data['course_title_ar']) ? trim((string) $data['course_title_ar']) : null;
            }
        } elseif (! empty($data['course_title_ar'])) {
            $courseTitle = trim((string) $data['course_title_ar']);
        }

        // Resolve Lesson if provided
        $lessonId = $homework->lesson_id;
        $lessonTitle = $homework->lesson_title_ar;
        if (array_key_exists('lesson_id', $data)) {
            if (! empty($data['lesson_id'])) {
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
                $lessonTitle = array_key_exists('lesson_title_ar', $data) ? trim((string) $data['lesson_title_ar']) : null;
            }
        } elseif (array_key_exists('lesson_title_ar', $data)) {
            $lessonTitle = trim((string) $data['lesson_title_ar']);
        }

        // Validate and upload replacement file BEFORE opening DB transaction
        $newStoredFile = null;
        if ($file) {
            $newStoredFile = $this->attachmentStorage->storeFile(
                user: $teacher,
                file: $file,
                subPath: "homeworks/{$teacher->id}/teacher_attachments",
                entityType: 'homework_attachment',
                entityId: $homework->uuid
            );
        }

        return $this->transaction(function () use (
            $teacher, $homework, $data, $courseId, $courseTitle, $lessonId, $lessonTitle, $newStoredFile
        ) {
            $oldDisk = $homework->attachment_disk ?: 'local';
            $oldPath = $homework->attachment_path;

            $attachmentName = $homework->attachment_name;
            $attachmentPath = $homework->attachment_path;
            $attachmentDisk = $homework->attachment_disk ?: 'local';
            $attachmentMime = $homework->attachment_mime_type;
            $attachmentSize = $homework->attachment_size_bytes;
            $fileType = $homework->file_type;

            $removeAttachment = ! empty($data['remove_attachment']);

            if ($newStoredFile) {
                if ($oldPath && $oldPath !== $newStoredFile['path']) {
                    $this->attachmentStorage->deleteFile($oldDisk, $oldPath);
                }
                $attachmentPath = $newStoredFile['path'];
                $attachmentDisk = $newStoredFile['disk'];
                $attachmentName = $newStoredFile['name'];
                $attachmentMime = $newStoredFile['mime_type'];
                $attachmentSize = $newStoredFile['size_bytes'];
                $fileType = $newStoredFile['file_type'];
            } elseif ($removeAttachment) {
                if ($oldPath) {
                    $this->attachmentStorage->deleteFile($oldDisk, $oldPath);
                }
                $attachmentPath = null;
                $attachmentDisk = 'local';
                $attachmentName = null;
                $attachmentMime = null;
                $attachmentSize = null;
                $fileType = null;
            }

            $updatePayload = [
                'course_id' => $courseId,
                'course_title_ar' => $courseTitle ?: 'المقرر الدراسي',
                'lesson_id' => $lessonId,
                'lesson_title_ar' => $lessonTitle,
                'attachment_name' => $attachmentName,
                'attachment_path' => $attachmentPath,
                'attachment_disk' => $attachmentDisk,
                'attachment_mime_type' => $attachmentMime,
                'attachment_size_bytes' => $attachmentSize,
                'file_type' => $fileType,
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
            } elseif ($newStoredFile) {
                $updatePayload['type'] = HomeworkType::FILE;
            } elseif ($removeAttachment) {
                $updatePayload['type'] = HomeworkType::TEXT;
            }
            if (array_key_exists('text_content', $data)) {
                $trimmedText = trim((string) $data['text_content']);
                $updatePayload['text_content'] = $trimmedText !== '' ? $trimmedText : null;
            }
            if (array_key_exists('due_date', $data)) {
                $updatePayload['due_date'] = ! empty($data['due_date'])
                    ? Carbon::parse($data['due_date'])->toDateString()
                    : null;
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
                    'teacher_id' => $teacher->id,
                    'homework_uuid' => $homework->uuid,
                ],
                'info',
                $homework
            );

            return $homework->fresh(['course', 'lesson']);
        });
    }

    /**
     * Soft-delete a homework.
     */
    public function deleteHomework(User $teacher, string $uuid): bool
    {
        $homework = $this->homeworkRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $homework) {
            $this->logSecurityEvent(
                'homework_deleted',
                [
                    'teacher_id' => $teacher->id,
                    'homework_uuid' => $homework->uuid,
                    'title_ar' => $homework->title_ar,
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
            'homework' => $homework,
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
                $rawScore = array_key_exists('score', $data) && $data['score'] !== null
                    ? (float) $data['score']
                    : (float) $maxGrade;
                $score = max(0.0, min((float) $maxGrade, $rawScore));
            } elseif ($status === HomeworkSubmissionStatus::REJECTED) {
                $score = 0.00;
            }

            $feedback = array_key_exists('feedback_ar', $data)
                ? trim(strip_tags((string) ($data['feedback_ar'] ?? '')))
                : $submission->feedback_ar;

            $submission->update([
                'status' => $status,
                'score' => $score,
                'max_score' => $maxGrade,
                'feedback_ar' => $feedback,
                'graded_at' => Carbon::now(),
                'graded_by' => $teacher->id,
            ]);

            // Recalculate parent homework submission metrics
            $homework->recalculateMetrics();

            $submission->logSecurityEvent(
                'homework_submission_graded',
                [
                    'teacher_id' => $teacher->id,
                    'homework_uuid' => $homework->uuid,
                    'submission_uuid' => $submission->uuid,
                    'status' => $status->value,
                    'score' => $score,
                ],
                'info',
                $submission
            );

            return $submission->fresh(['student', 'grader', 'homework']);
        });
    }
}
