<?php

namespace App\Repositories\Contracts;

use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface LessonRepositoryInterface extends BaseRepositoryInterface
{
    public function getForTeacher(int $teacherId, array $filters = []): Collection;

    public function findByUuidForTeacher(string $uuid, User $teacher): Lesson;

    public function findAttachmentByUuidForTeacher(string $attachmentUuid, Lesson $lesson, User $teacher): LessonAttachment;
}

