<?php

namespace App\Repositories\Eloquent;

use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\User;
use App\Repositories\Contracts\LessonRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class LessonRepository extends BaseRepository implements LessonRepositoryInterface
{
    public function __construct(Lesson $model)
    {
        parent::__construct($model);
    }

    public function getForTeacher(int $teacherId, array $filters = []): Collection
    {
        $query = $this->model
            ->forTeacher($teacherId)
            ->with(['course', 'attachments', 'chapters', 'transcriptSegments', 'conceptGraph']);

        if (!empty($filters['course_id'])) {
            $query->where('course_id', (int) $filters['course_id']);
        }

        if (!empty($filters['course_uuid'])) {
            $query->whereHas('course', function ($q) use ($filters) {
                $q->where('uuid', $filters['course_uuid']);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                    ->orWhere('title_en', 'like', "%{$search}%")
                    ->orWhere('description_ar', 'like', "%{$search}%")
                    ->orWhere('subject_ar', 'like', "%{$search}%")
                    ->orWhere('grade_ar', 'like', "%{$search}%");
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function findByUuidForTeacher(string $uuid, User $teacher): Lesson
    {
        /** @var Lesson|null $lesson */
        $lesson = $this->model
            ->with(['course', 'attachments', 'chapters', 'transcriptSegments', 'conceptGraph'])
            ->where('uuid', $uuid)
            ->first();

        if (!$lesson) {
            throw (new ModelNotFoundException())->setModel(Lesson::class, [$uuid]);
        }

        $lesson->assertOwnedBy($teacher);

        return $lesson;
    }

    public function findAttachmentByUuidForTeacher(string $attachmentUuid, Lesson $lesson, User $teacher): LessonAttachment
    {
        $lesson->assertOwnedBy($teacher);

        /** @var LessonAttachment|null $attachment */
        $attachment = LessonAttachment::where('uuid', $attachmentUuid)
            ->where('lesson_id', $lesson->id)
            ->first();

        if (!$attachment) {
            throw (new ModelNotFoundException())->setModel(LessonAttachment::class, [$attachmentUuid]);
        }

        $attachment->assertOwnedBy($teacher);

        return $attachment;
    }
}

