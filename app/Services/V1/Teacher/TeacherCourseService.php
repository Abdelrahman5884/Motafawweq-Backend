<?php

namespace App\Services\V1\Teacher;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;
use App\Repositories\Contracts\CourseRepositoryInterface;
use App\Repositories\Contracts\TeacherProfileRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;

class TeacherCourseService extends BaseService
{
    public function __construct(
        protected CourseRepositoryInterface $courseRepository,
        protected TeacherProfileRepositoryInterface $profileRepository
    ) {}

    /**
     * List all courses owned by the authenticated teacher with optional search/filter.
     */
    public function listCourses(User $teacher, array $filters = []): Collection
    {
        return $this->courseRepository->getForTeacher($teacher->id, $filters);
    }

    /**
     * Retrieve a single course owned by the authenticated teacher.
     */
    public function getCourse(User $teacher, string $uuid): Course
    {
        return $this->courseRepository->findByUuidForTeacher($uuid, $teacher);
    }

    /**
     * Create a new curriculum course for the teacher.
     */
    public function createCourse(User $teacher, array $data): Course
    {
        return $this->transaction(function () use ($teacher, $data) {
            $profile = $this->profileRepository->firstOrCreateForTeacher($teacher);

            $titleAr = trim((string) ($data['title_ar'] ?? $data['titleAr'] ?? $data['title'] ?? ''));
            $titleEn = trim((string) ($data['title_en'] ?? $data['titleEn'] ?? $data['title'] ?? $titleAr));

            $gradeAr = trim((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? $data['grade'] ?? 'الصف الثالث الثانوي'));
            $rawStage = trim((string) ($data['stage_id'] ?? $data['stageId'] ?? $data['stage'] ?? 'sec'));
            [$stageId, $stageNameAr] = $this->resolveStageInfo($rawStage, $gradeAr);

            $subjectAr = trim((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? $data['subject'] ?? $profile->subject_ar ?? 'الأحياء'));
            $subjectEn = trim((string) ($data['subject_en'] ?? $data['subjectEn'] ?? $profile->subject_en ?? $subjectAr));

            $priceEgp = (float) ($data['price_egp'] ?? $data['priceEgp'] ?? $data['price'] ?? 350);
            $descriptionAr = trim((string) ($data['description_ar'] ?? $data['descriptionAr'] ?? $data['description'] ?? 'مقرر تعليمي جديد تم إنشاؤه عبر منصة متفوق.'));
            $descriptionEn = trim((string) ($data['description_en'] ?? $data['descriptionEn'] ?? $descriptionAr));

            $hoursTotal = trim((string) ($data['hours_total'] ?? $data['hoursTotal'] ?? $data['hours'] ?? '2 ساعة'));
            $lessonsCount = max(1, (int) ($data['lessons_count'] ?? $data['lessonsCount'] ?? 1));
            $status = CourseStatus::tryFrom((string) ($data['status'] ?? 'published')) ?? CourseStatus::PUBLISHED;

            /** @var Course $course */
            $course = $this->courseRepository->create([
                'teacher_id'     => $teacher->id,
                'title_ar'       => $titleAr,
                'title_en'       => $titleEn,
                'stage_id'       => $stageId,
                'stage_name_ar'  => $stageNameAr,
                'grade_ar'       => $gradeAr,
                'subject_ar'     => $subjectAr,
                'subject_en'     => $subjectEn,
                'price_egp'      => $priceEgp,
                'description_ar' => $descriptionAr,
                'description_en' => $descriptionEn,
                'cover_url'      => $data['cover_url'] ?? $data['cover'] ?? null,
                'hours_total'    => $hoursTotal !== '' ? $hoursTotal : '2 ساعة',
                'lessons_count'  => $lessonsCount,
                'status'         => $status,
            ]);

            $this->logSecurityEvent(
                'teacher.course.created',
                [
                    'course_uuid' => $course->uuid,
                    'title_ar'    => $course->title_ar,
                    'stage_id'    => $course->stage_id,
                    'grade_ar'    => $course->grade_ar,
                    'price_egp'   => $course->price_egp,
                ],
                'info',
                $course
            );

            return $course->fresh();
        });
    }

    /**
     * Update an existing course owned by the teacher.
     */
    public function updateCourse(User $teacher, string $uuid, array $data): Course
    {
        // Enforce ownership BEFORE opening transaction so anti-IDOR audit logs are never rolled back
        $course = $this->courseRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($course, $data) {
            $updates = [];

            if (array_key_exists('title_ar', $data) || array_key_exists('titleAr', $data) || array_key_exists('title', $data)) {
                $titleAr = trim((string) ($data['title_ar'] ?? $data['titleAr'] ?? $data['title']));
                if ($titleAr !== '') {
                    $updates['title_ar'] = $titleAr;
                }
            }

            if (array_key_exists('title_en', $data) || array_key_exists('titleEn', $data)) {
                $updates['title_en'] = trim((string) ($data['title_en'] ?? $data['titleEn']));
            } elseif (isset($updates['title_ar']) && !array_key_exists('title_en', $data)) {
                $updates['title_en'] = $updates['title_ar'];
            }

            if (array_key_exists('grade_ar', $data) || array_key_exists('gradeAr', $data) || array_key_exists('grade', $data)) {
                $updates['grade_ar'] = trim((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? $data['grade']));
            }

            if (array_key_exists('stage_id', $data) || array_key_exists('stageId', $data) || array_key_exists('stage', $data)) {
                $rawStage = trim((string) ($data['stage_id'] ?? $data['stageId'] ?? $data['stage']));
                [$stageId, $stageNameAr] = $this->resolveStageInfo($rawStage, $updates['grade_ar'] ?? $course->grade_ar);
                $updates['stage_id'] = $stageId;
                $updates['stage_name_ar'] = $stageNameAr;
            }

            if (array_key_exists('subject_ar', $data) || array_key_exists('subjectAr', $data) || array_key_exists('subject', $data)) {
                $updates['subject_ar'] = trim((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? $data['subject']));
            }

            if (array_key_exists('subject_en', $data) || array_key_exists('subjectEn', $data)) {
                $updates['subject_en'] = trim((string) ($data['subject_en'] ?? $data['subjectEn']));
            }

            if (array_key_exists('price_egp', $data) || array_key_exists('priceEgp', $data) || array_key_exists('price', $data)) {
                $updates['price_egp'] = (float) ($data['price_egp'] ?? $data['priceEgp'] ?? $data['price']);
            }

            if (array_key_exists('description_ar', $data) || array_key_exists('descriptionAr', $data) || array_key_exists('description', $data)) {
                $updates['description_ar'] = trim((string) ($data['description_ar'] ?? $data['descriptionAr'] ?? $data['description']));
            }

            if (array_key_exists('description_en', $data) || array_key_exists('descriptionEn', $data)) {
                $updates['description_en'] = trim((string) ($data['description_en'] ?? $data['descriptionEn']));
            }

            if (array_key_exists('hours_total', $data) || array_key_exists('hoursTotal', $data) || array_key_exists('hours', $data)) {
                $hours = trim((string) ($data['hours_total'] ?? $data['hoursTotal'] ?? $data['hours']));
                if ($hours !== '') {
                    $updates['hours_total'] = $hours;
                }
            }

            if (array_key_exists('lessons_count', $data) || array_key_exists('lessonsCount', $data)) {
                $updates['lessons_count'] = max(1, (int) ($data['lessons_count'] ?? $data['lessonsCount']));
            }

            if (array_key_exists('status', $data)) {
                $status = CourseStatus::tryFrom((string) $data['status']);
                if ($status) {
                    $updates['status'] = $status;
                }
            }

            if (!empty($updates)) {
                $course->update($updates);
            }

            $this->logSecurityEvent(
                'teacher.course.updated',
                [
                    'course_uuid'    => $course->uuid,
                    'updated_fields' => array_keys($updates),
                ],
                'info',
                $course
            );

            return $course->fresh();
        });
    }

    /**
     * Soft-delete a course owned by the teacher.
     */
    public function deleteCourse(User $teacher, string $uuid): void
    {
        // Enforce ownership BEFORE opening transaction so anti-IDOR audit logs are never rolled back
        $course = $this->courseRepository->findByUuidForTeacher($uuid, $teacher);

        $this->transaction(function () use ($course) {
            $courseUuid = $course->uuid;
            $titleAr = $course->title_ar;

            $course->delete();

            $this->logSecurityEvent(
                'teacher.course.deleted',
                [
                    'course_uuid' => $courseUuid,
                    'title_ar'    => $titleAr,
                ],
                'warning'
            );
        });
    }

    /**
     * Normalize stage code and Arabic display name.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveStageInfo(string $rawStage, ?string $gradeAr = null): array
    {
        $normalized = strtolower($rawStage);

        if (in_array($normalized, ['prep', 'stage-prep'], true) || $rawStage === 'المرحلة الإعدادية' || ($gradeAr && str_contains($gradeAr, 'الإعدادي'))) {
            return ['prep', 'المرحلة الإعدادية'];
        }

        if (in_array($normalized, ['prim', 'primary', 'stage-primary'], true) || $rawStage === 'المرحلة الابتدائية' || ($gradeAr && str_contains($gradeAr, 'الابتدائي'))) {
            return ['prim', 'المرحلة الابتدائية'];
        }

        return ['sec', 'المرحلة الثانوية'];
    }
}

